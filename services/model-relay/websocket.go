package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/gorilla/websocket"
)

type websocketDial func(context.Context, string, http.Header) (*websocket.Conn, *http.Response, error)
type wsPacket struct {
	data []byte
	err  error
}

func wsRead(ctx context.Context, socket *websocket.Conn, out chan<- wsPacket) {
	defer close(out)
	for {
		kind, data, err := socket.ReadMessage()
		if err == nil && kind != websocket.TextMessage {
			continue
		}
		select {
		case out <- wsPacket{data, err}:
		case <-ctx.Done():
			return
		}
		if err != nil {
			return
		}
	}
}

func (g *gateway) responsesWS(w http.ResponseWriter, r *http.Request) {
	key := clientKey(r)
	if r.Method != "GET" || !strings.HasPrefix(key, "bc-") || len(key) > 100 {
		apiError(w, 401, "Invalid API key")
		return
	}
	select {
	case g.slots <- struct{}{}:
		defer func() { <-g.slots }()
	default:
		apiError(w, 503, "Gateway busy")
		return
	}
	var config reservation
	if status, err := g.callback("connect", map[string]string{"key": key, "protocol": "responses_ws"}, &config); err != nil {
		apiError(w, status, "WebSocket key or protocol unavailable")
		return
	}
	ctx, cancel := context.WithTimeout(g.rootContext(), 55*time.Minute)
	defer cancel()
	u, err := url.Parse(config.URL + "/responses")
	if err != nil || (u.Scheme != "https" && g.wsDial == nil) {
		apiError(w, 502, "Invalid upstream")
		return
	}
	if u.Scheme == "https" {
		u.Scheme = "wss"
	} else {
		u.Scheme = "ws"
	}
	dial := g.wsDial
	if dial == nil {
		dial = (&websocket.Dialer{NetDialContext: publicDial, HandshakeTimeout: 15 * time.Second}).DialContext
	}
	upstream, resp, err := dial(ctx, u.String(), http.Header{"Authorization": []string{"Bearer " + config.Key}})
	if err != nil {
		if resp != nil && resp.Body != nil {
			resp.Body.Close()
		}
		apiError(w, 502, "Upstream WebSocket unavailable")
		return
	}
	defer upstream.Close()
	upgrader := websocket.Upgrader{HandshakeTimeout: 10 * time.Second}
	client, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	defer client.Close()
	client.SetReadLimit(512 * 1024)
	upstream.SetReadLimit(1024 * 1024)
	fromClient, fromUpstream := make(chan wsPacket), make(chan wsPacket)
	go wsRead(ctx, client, fromClient)
	go wsRead(ctx, upstream, fromUpstream)
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()
	var active *settlement
	var started time.Time
	var lane string
	var meter protocolUsage
	lastActivity := time.Now()
	finish := func(status, code string) {
		if active == nil {
			return
		}
		meter.apply(active)
		active.Status = status
		active.Error = code
		active.Duration = time.Since(started).Milliseconds()
		g.settle(*active)
		active = nil
	}
	defer func() { finish("interrupted", "stream_interrupted") }()
	write := func(socket *websocket.Conn, data []byte) error {
		_ = socket.SetWriteDeadline(time.Now().Add(15 * time.Second))
		return socket.WriteMessage(websocket.TextMessage, data)
	}
	reject := func(status int, message, streamID string) error {
		event := map[string]any{"type": "error", "status": status, "error": map[string]string{"type": "relay_error", "message": message}}
		if streamID != "" {
			event["stream_id"] = streamID
		}
		data, _ := json.Marshal(event)
		return write(client, data)
	}
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if active != nil && time.Since(started) > 8*time.Minute {
				_ = reject(504, "Response timed out", lane)
				return
			}
			if active == nil && time.Since(lastActivity) > 2*time.Minute {
				return
			}
			if client.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second)) != nil || upstream.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second)) != nil {
				return
			}
		case packet, ok := <-fromClient:
			if !ok || packet.err != nil {
				return
			}
			lastActivity = time.Now()
			var event map[string]any
			if json.Unmarshal(packet.data, &event) != nil {
				if reject(422, "Invalid JSON", "") != nil {
					return
				}
				continue
			}
			streamID, _ := event["stream_id"].(string)
			if event["type"] == "response.cancel" && active != nil {
				if (event["response_id"] != nil && event["response_id"] != meter.responseID) || (streamID != "" && streamID != lane) {
					if reject(422, "Only the active response on this connection can be cancelled", streamID) != nil {
						return
					}
					continue
				}
				if write(upstream, packet.data) != nil {
					return
				}
				continue
			}
			if event["type"] != "response.create" {
				if reject(422, "Use response.create", streamID) != nil {
					return
				}
				continue
			}
			if active != nil {
				if reject(429, "One in-flight response per connection; wait for completion", streamID) != nil {
					return
				}
				continue
			}
			delete(event, "type")
			delete(event, "stream")
			delete(event, "stream_id")
			body, _ := json.Marshal(event)
			spec, err := decodeRequest("/v1/responses", body)
			if err != nil {
				if reject(422, err.Error(), streamID) != nil {
					return
				}
				continue
			}
			id := make([]byte, 16)
			if _, err := rand.Read(id); err != nil {
				return
			}
			requestID := hex.EncodeToString(id)
			var reserved reservation
			status, err := g.callback("reserve", map[string]any{"key": key, "protocol": "responses_ws", "request_id": requestID, "model": spec.Model,
				"previous_response_id": spec.Previous, "input_bound": len(body) + 4096, "max_output": spec.Max}, &reserved)
			if err != nil {
				if reject(status, "Key, model or quota unavailable", streamID) != nil {
					return
				}
				continue
			}
			active = &settlement{ID: requestID, HTTP: 101}
			started = time.Now()
			lane = streamID
			meter = protocolUsage{protocol: "responses_ws"}
			if reserved.URL != config.URL || reserved.Key != config.Key {
				finish("rejected", "upstream_rejected")
				_ = reject(409, "Upstream changed; reconnect", streamID)
				return
			}
			spec.capOutput(reserved.Max)
			spec.Body["type"] = "response.create"
			if streamID != "" {
				spec.Body["stream_id"] = streamID
			}
			forwarded, _ := json.Marshal(spec.Body)
			if write(upstream, forwarded) != nil {
				return
			}
		case packet, ok := <-fromUpstream:
			if !ok || packet.err != nil {
				return
			}
			if active != nil {
				meter.consume(packet.data)
				if meter.done {
					if meter.failed {
						finish("interrupted", "upstream_rejected")
					} else {
						finish("complete", "")
					}
				} else if meter.failed {
					finish("unknown", "upstream_rejected")
				}
			}
			if write(client, packet.data) != nil {
				return
			}
			lastActivity = time.Now()
		}
	}
}

func (g *gateway) rootContext() context.Context {
	if g.context != nil {
		return g.context
	}
	return context.Background()
}
