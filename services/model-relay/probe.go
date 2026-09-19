package main

import (
	"bytes"
	"context"
	"crypto/subtle"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"time"
)

func (g *gateway) probe(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" || len(g.secret) < 32 || subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Relay-Secret")), []byte(g.secret)) != 1 {
		apiError(w, 403, "Forbidden")
		return
	}
	var input struct {
		ID       int    `json:"id"`
		Protocol string `json:"protocol"`
		Model    string `json:"model"`
		Prompt   string `json:"prompt"`
		Max      int    `json:"max_output"`
	}
	if json.NewDecoder(http.MaxBytesReader(w, r.Body, 16384)).Decode(&input) != nil || input.ID < 1 || len(input.Prompt) > 8000 || input.Max < 16 || input.Max > 1024 {
		apiError(w, 422, "Invalid probe")
		return
	}
	select {
	case g.slots <- struct{}{}:
		defer func() { <-g.slots }()
	default:
		apiError(w, 503, "Gateway busy")
		return
	}
	var upstream reservation
	if code, err := g.callback("upstream", map[string]int{"id": input.ID}, &upstream); err != nil {
		apiError(w, code, "Upstream unavailable")
		return
	}
	has := func(list []string, item string) bool {
		for _, value := range list {
			if value == item {
				return true
			}
		}
		return false
	}
	if !upstream.Enabled || !has(upstream.Models, input.Model) || !has(upstream.Protocols, input.Protocol) || input.Protocol == "responses_ws" {
		apiError(w, 422, "Model or test protocol unavailable")
		return
	}
	p := map[string]any{"model": input.Model, "messages": []any{map[string]string{"role": "user", "content": input.Prompt}}, "max_tokens": input.Max}
	path := "/chat/completions"
	switch input.Protocol {
	case "responses":
		path = "/responses"
		p = map[string]any{"model": input.Model, "input": input.Prompt, "max_output_tokens": input.Max, "store": false}
	case "anthropic":
		path = "/messages"
	case "gemini":
		if !geminiPath.MatchString("/v1beta/models/" + input.Model + ":generateContent") {
			apiError(w, 422, "Invalid Gemini model")
			return
		}
		path = "/models/" + input.Model + ":generateContent"
		p = map[string]any{"contents": []any{map[string]any{"role": "user", "parts": []any{map[string]string{"text": input.Prompt}}}}, "generationConfig": map[string]any{"maxOutputTokens": input.Max}}
	}
	base := upstream.URL
	if upstream.URLs[input.Protocol] != "" {
		base = upstream.URLs[input.Protocol]
	}
	body, _ := json.Marshal(p)
	ctx, cancel := context.WithTimeout(r.Context(), 40*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, "POST", base+path, bytes.NewReader(body))
	if err != nil {
		apiError(w, 422, "Invalid upstream URL")
		return
	}
	setUpstreamHeaders(req, input.Protocol, upstream.Key, nil)
	start := time.Now()
	result := map[string]any{"ok": false, "error_code": "upstream_network", "http_status": 0, "tokens": nil}
	response, err := g.client.Do(req)
	if err == nil {
		defer response.Body.Close()
		result["http_status"] = response.StatusCode
		if response.StatusCode < 200 || response.StatusCode >= 300 {
			result["error_code"] = upstreamError(response)
		} else if data, err := io.ReadAll(io.LimitReader(response.Body, 1024*1024+1)); err == nil && len(data) <= 1024*1024 {
			meter := protocolUsage{protocol: input.Protocol}
			meter.consume(data)
			if json.Valid(data) && !meter.failed {
				result["ok"] = true
				result["error_code"] = nil
				result["text"] = extractText(input.Protocol, data)
				result["input_tokens"], result["output_tokens"] = meter.input, meter.output
				result["cache_read_tokens"], result["cache_write_tokens"] = meter.cacheRead, meter.cacheWrite
				if meter.input != nil && meter.output != nil {
					result["tokens"] = *meter.input + *meter.output
				}
			} else {
				result["error_code"] = "invalid_response"
			}
		}
	}
	result["duration_ms"] = time.Since(start).Milliseconds()
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(result)
}

func extractText(protocol string, data []byte) string {
	var p map[string]any
	_ = json.Unmarshal(data, &p)
	var texts []string
	var blocks func(any)
	blocks = func(value any) {
		switch v := value.(type) {
		case []any:
			for _, item := range v {
				blocks(item)
			}
		case map[string]any:
			if v["thought"] == true {
				return
			}
			if text, ok := v["text"].(string); ok {
				texts = append(texts, text)
			}
			if content, ok := v["content"].(string); ok {
				texts = append(texts, content)
			} else {
				blocks(v["content"])
			}
			blocks(v["parts"])
		}
	}
	switch protocol {
	case "responses":
		blocks(p["output"])
	case "anthropic":
		blocks(p["content"])
	case "gemini":
		blocks(p["candidates"])
	default:
		if choices, ok := p["choices"].([]any); ok && len(choices) > 0 {
			if choice, ok := choices[0].(map[string]any); ok {
				blocks(choice["message"])
			}
		}
	}
	text := strings.Join(texts, "\n")
	if len(text) > 4000 {
		text = text[:4000]
	}
	return text
}
