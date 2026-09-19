package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

type gateway struct {
	panel, secret, host, spool string
	client                     *http.Client
	callbacks                  *http.Client
	slots                      chan struct{}
	settleMu                   sync.Mutex
	context                    context.Context
	wsDial                     websocketDial
}

func apiError(w http.ResponseWriter, status int, message string) {
	if status < 400 || status > 599 {
		status = 502
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(map[string]any{"error": map[string]string{"message": message, "type": "relay_error"}})
}

func (g *gateway) callback(operation string, body any, result any) (int, error) {
	data, err := json.Marshal(body)
	if err != nil {
		return 500, err
	}
	req, err := http.NewRequest("POST", g.panel+"/api/v2/model-relay/internal/"+operation, bytes.NewReader(data))
	if err != nil {
		return 500, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Relay-Secret", g.secret)
	if g.host != "" {
		req.Host = g.host
	}
	resp, err := g.callbacks.Do(req)
	if err != nil {
		return 503, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return resp.StatusCode, errors.New("panel rejected request")
	}
	if result == nil {
		return 200, nil
	}
	return 200, json.NewDecoder(io.LimitReader(resp.Body, 65536)).Decode(result)
}

type reservation struct {
	URL       string            `json:"base_url"`
	Key       string            `json:"api_key"`
	Max       int               `json:"max_output"`
	Protocols []string          `json:"protocols"`
	URLs      map[string]string `json:"protocol_urls"`
	Models    []string          `json:"models"`
	Enabled   bool              `json:"enabled"`
}
type settlement struct {
	ID         string `json:"request_id"`
	Status     string `json:"status"`
	Input      *int64 `json:"input_tokens"`
	Output     *int64 `json:"output_tokens"`
	HTTP       int    `json:"http_status"`
	Duration   int64  `json:"duration_ms"`
	Error      string `json:"error_code,omitempty"`
	ResponseID string `json:"response_id,omitempty"`
	CacheRead  *int64 `json:"cache_read_tokens"`
	CacheWrite *int64 `json:"cache_write_tokens"`
}

func usage(data []byte, s *settlement) {
	var envelope struct {
		Usage *struct {
			Input  *int64 `json:"prompt_tokens"`
			Output *int64 `json:"completion_tokens"`
		} `json:"usage"`
	}
	if json.Unmarshal(data, &envelope) == nil && envelope.Usage != nil && envelope.Usage.Input != nil && envelope.Usage.Output != nil && *envelope.Usage.Input >= 0 && *envelope.Usage.Output >= 0 {
		s.Input, s.Output = envelope.Usage.Input, envelope.Usage.Output
	}
}

// Durable settlement retry contains counters only, never prompts or keys.
func (g *gateway) settle(s settlement) {
	g.settleMu.Lock()
	data, _ := json.Marshal(s)
	path := filepath.Join(g.spool, s.ID+".json")
	if err := os.WriteFile(path+".tmp", data, 0600); err != nil {
		g.settleMu.Unlock()
		log.Printf("settlement spool error request=%s", s.ID)
		return
	}
	if file, err := os.OpenFile(path+".tmp", os.O_RDWR, 0600); err == nil {
		_ = file.Sync()
		_ = file.Close()
	}
	if err := os.Rename(path+".tmp", path); err != nil {
		g.settleMu.Unlock()
		log.Printf("settlement rename error request=%s", s.ID)
		return
	}
	g.settleMu.Unlock()
	if _, err := g.callback("settle", s, nil); err == nil {
		_ = os.Remove(path)
	}
}

func (g *gateway) retry(ctx context.Context) {
	timer := time.NewTicker(30 * time.Second)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
		paths, _ := filepath.Glob(filepath.Join(g.spool, "*.json"))
		for i, path := range paths {
			if i >= 100 {
				break
			}
			data, err := os.ReadFile(path)
			var s settlement
			if err == nil && json.Unmarshal(data, &s) == nil {
				if _, err = g.callback("settle", s, nil); err == nil {
					_ = os.Remove(path)
				}
			}
		}
	}
}

func (g *gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/health" {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok"}`))
		return
	}
	if r.URL.Path == "/admin/test" {
		g.testUpstream(w, r)
		return
	}
	if r.URL.Path == "/admin/probe" {
		g.probe(w, r)
		return
	}
	if r.URL.Path == "/v1/responses" && strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		g.responsesWS(w, r)
		return
	}
	if r.URL.Path != "/v1/models" && r.URL.Path != "/v1beta/models" && protocolPath(r.URL.Path) == "" {
		apiError(w, 404, "Unsupported endpoint")
		return
	}
	key := clientKey(r)
	if !strings.HasPrefix(key, "bc-") || len(key) > 100 {
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
	if r.URL.Path == "/v1/models" || r.URL.Path == "/v1beta/models" {
		if r.Method != "GET" {
			apiError(w, 405, "Use GET")
			return
		}
		var out struct {
			Models []string `json:"models"`
		}
		params := map[string]string{"key": key}
		if r.URL.Path == "/v1beta/models" {
			params["protocol"] = "gemini"
		}
		status, err := g.callback("models", params, &out)
		if err != nil {
			apiError(w, status, "Key or upstream unavailable")
			return
		}
		models := []map[string]string{}
		for _, name := range out.Models {
			models = append(models, map[string]string{"id": name, "object": "model", "owned_by": "buncloud"})
		}
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/v1beta/models" {
			list := []map[string]any{}
			for _, name := range out.Models {
				list = append(list, map[string]any{"name": "models/" + name, "displayName": name, "supportedGenerationMethods": []string{"generateContent", "streamGenerateContent"}})
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"models": list})
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"object": "list", "data": models})
		return
	}
	if r.Method != "POST" {
		apiError(w, 405, "Use POST")
		return
	}
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 512*1024))
	if err != nil {
		apiError(w, 413, "Request too large")
		return
	}
	spec, err := decodeRequest(r.URL.Path, body)
	if err != nil {
		apiError(w, 422, err.Error())
		return
	}
	id := make([]byte, 16)
	if _, err := rand.Read(id); err != nil {
		apiError(w, 503, "Entropy unavailable")
		return
	}
	requestID := hex.EncodeToString(id)
	var reserved reservation
	status, err := g.callback("reserve", map[string]any{"key": key, "request_id": requestID, "model": spec.Model, "protocol": spec.Protocol, "previous_response_id": spec.Previous, "input_bound": len(body) + 4096, "max_output": spec.Max}, &reserved)
	if err != nil {
		apiError(w, status, "Key, model or quota unavailable; check machine allowance")
		return
	}
	start := time.Now()
	settled := settlement{ID: requestID, Status: "unknown", Error: "upstream_network"}
	w.Header().Set("X-Request-ID", requestID)
	defer func() { settled.Duration = time.Since(start).Milliseconds(); g.settle(settled) }()
	spec.capOutput(reserved.Max)
	forwarded, _ := json.Marshal(spec.Body)
	root := g.context
	if root == nil {
		root = context.Background()
	}
	ctx, cancel := context.WithTimeout(root, 8*time.Minute)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, "POST", reserved.URL+spec.Path, bytes.NewReader(forwarded))
	if err != nil {
		settled.Status = "rejected"
		apiError(w, 502, "Invalid upstream")
		return
	}
	setUpstreamHeaders(req, spec.Protocol, reserved.Key, r.Header)
	resp, err := g.client.Do(req)
	if err != nil {
		apiError(w, 502, "Upstream connection failed")
		return
	}
	defer resp.Body.Close()
	settled.HTTP = resp.StatusCode
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		settled.Status = "rejected"
		settled.Error = upstreamError(resp)
		apiError(w, 502, settled.Error+"; see request log")
		return
	}
	settled.Error = "invalid_response"
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Request-ID", requestID)
	meter := protocolUsage{protocol: spec.Protocol}
	defer meter.apply(&settled)
	if !spec.Stream {
		data, err := io.ReadAll(io.LimitReader(resp.Body, 4*1024*1024+1))
		if err != nil {
			settled.Error = "upstream_network"
			apiError(w, 502, "Upstream response interrupted or timed out")
			return
		}
		if len(data) > 4*1024*1024 {
			apiError(w, 502, "Upstream response too large")
			return
		}
		if !json.Valid(data) {
			apiError(w, 502, "Invalid upstream response")
			return
		}
		meter.consume(data)
		settled.Status = "complete"
		settled.Error = ""
		if meter.failed {
			settled.Status = "interrupted"
			settled.Error = "upstream_rejected"
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write(data)
		return
	}
	if !strings.Contains(strings.ToLower(resp.Header.Get("Content-Type")), "text/event-stream") {
		apiError(w, 502, "Upstream did not return an event stream")
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("X-Accel-Buffering", "no")
	scanner := bufio.NewScanner(resp.Body)
	scanner.Buffer(make([]byte, 4096), 1024*1024)
	connected := true
	var eventData []byte
	for scanner.Scan() {
		line := scanner.Bytes()
		if bytes.HasPrefix(line, []byte("data:")) {
			eventData = append(eventData, bytes.TrimSpace(bytes.TrimPrefix(line, []byte("data:")))...)
			eventData = append(eventData, '\n')
		}
		if len(eventData) > 1024*1024 {
			break
		}
		if len(line) == 0 && len(eventData) > 0 {
			meter.consume(eventData)
			eventData = nil
		}
		if connected {
			_, err := w.Write(append(append([]byte{}, line...), '\n'))
			if err != nil {
				connected = false
			}
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
		}
	}
	if len(eventData) > 0 && len(eventData) <= 1024*1024 {
		meter.consume(eventData)
	}
	settled.Status = "interrupted"
	settled.Error = "stream_interrupted"
	if scanner.Err() == nil && meter.done && !meter.failed {
		settled.Status = "complete"
		settled.Error = ""
	}
}

func upstreamError(resp *http.Response) string {
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 8192))
	if resp.StatusCode == 403 && strings.Contains(strings.ToLower(resp.Header.Get("Server")), "cloudflare") && (bytes.Contains(body, []byte("Attention Required!")) || bytes.Contains(body, []byte("you have been blocked")) || resp.Header.Get("cf-mitigated") == "challenge") {
		return "cloudflare_blocked"
	}
	return "upstream_rejected"
}

func (g *gateway) testUpstream(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" || len(g.secret) < 32 || subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Relay-Secret")), []byte(g.secret)) != 1 {
		apiError(w, 403, "Forbidden")
		return
	}
	var input struct {
		ID int `json:"id"`
	}
	if json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&input) != nil || input.ID < 1 {
		apiError(w, 422, "Invalid upstream ID")
		return
	}
	var upstream reservation
	if status, err := g.callback("upstream", input, &upstream); err != nil {
		apiError(w, status, "Upstream configuration unavailable")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()
	start := time.Now()
	protocol := "openai"
	if len(upstream.Protocols) > 0 {
		protocol = upstream.Protocols[0]
	}
	if protocol == "responses_ws" {
		protocol = "responses"
	}
	base := upstream.URL
	if upstream.URLs[protocol] != "" {
		base = upstream.URLs[protocol]
	}
	req, err := http.NewRequestWithContext(ctx, "GET", base+"/models", nil)
	if err != nil {
		apiError(w, 422, "Invalid upstream URL")
		return
	}
	setUpstreamHeaders(req, protocol, upstream.Key, nil)
	resp, err := g.client.Do(req)
	result := map[string]any{"ok": false, "http_status": 0, "error_code": "upstream_network"}
	if err == nil {
		defer resp.Body.Close()
		result["http_status"] = resp.StatusCode
		if resp.StatusCode == 200 {
			var body struct {
				Data []struct {
					ID string `json:"id"`
				} `json:"data"`
				Models []any `json:"models"`
			}
			if json.NewDecoder(io.LimitReader(resp.Body, 1024*1024)).Decode(&body) == nil && (body.Data != nil || body.Models != nil) {
				result["ok"] = true
				result["model_count"] = len(body.Data)
				if protocol == "gemini" {
					result["model_count"] = len(body.Models)
				}
				result["error_code"] = nil
			} else {
				result["error_code"] = "invalid_response"
			}
		} else {
			result["error_code"] = upstreamError(resp)
		}
	}
	result["duration_ms"] = time.Since(start).Milliseconds()
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(result)
}

func validatePayload(body []byte) (map[string]any, string, *int, bool, error) {
	spec, err := decodeRequest("/v1/chat/completions", body)
	return spec.Body, spec.Model, spec.Max, spec.Stream, err
}

func publicDial(ctx context.Context, network, address string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		return nil, err
	}
	ips, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil {
		return nil, err
	}
	for _, ip := range ips {
		if ip.IP.IsPrivate() || ip.IP.IsLoopback() || ip.IP.IsLinkLocalUnicast() || !ip.IP.IsGlobalUnicast() {
			return nil, errors.New("upstream must resolve to public addresses")
		}
	}
	dialer := net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}
	for _, ip := range ips {
		conn, dialErr := dialer.DialContext(ctx, network, net.JoinHostPort(ip.IP.String(), port))
		if dialErr == nil {
			return conn, nil
		}
		err = dialErr
	}
	if err == nil {
		err = errors.New("upstream has no address")
	}
	return nil, err
}

func main() {
	secret, err := os.ReadFile(os.Getenv("RELAY_SECRET_FILE"))
	if err != nil || len(bytes.TrimSpace(secret)) < 32 {
		log.Fatal("RELAY_SECRET_FILE is required")
	}
	spool := os.Getenv("RELAY_SPOOL")
	if spool == "" {
		log.Fatal("RELAY_SPOOL is required")
	}
	if err := os.MkdirAll(spool, 0700); err != nil {
		log.Fatal("Cannot create spool")
	}
	noRedirect := func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = nil
	transport.DialContext = publicDial
	transport.ResponseHeaderTimeout = 90 * time.Second
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	g := &gateway{context: ctx, panel: strings.TrimRight(os.Getenv("RELAY_PANEL_URL"), "/"), host: os.Getenv("RELAY_PANEL_HOST"), secret: string(bytes.TrimSpace(secret)), spool: spool, slots: make(chan struct{}, 16),
		client: &http.Client{Transport: transport, Timeout: 8 * time.Minute, CheckRedirect: noRedirect}, callbacks: &http.Client{Timeout: 12 * time.Second, CheckRedirect: noRedirect}}
	if g.panel == "" {
		log.Fatal("RELAY_PANEL_URL is required")
	}
	go g.retry(ctx)
	server := &http.Server{Addr: "127.0.0.1:18941", Handler: g, ReadHeaderTimeout: 10 * time.Second, ReadTimeout: 30 * time.Second, WriteTimeout: 9 * time.Minute, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16384}
	stopped := make(chan os.Signal, 1)
	signal.Notify(stopped, syscall.SIGTERM, os.Interrupt)
	go func() {
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal(err)
		}
	}()
	<-stopped
	cancel()
	shutdown, done := context.WithTimeout(context.Background(), 25*time.Second)
	defer done()
	_ = server.Shutdown(shutdown)
}
