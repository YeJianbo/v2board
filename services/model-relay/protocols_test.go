package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestNativeProtocolMetering(t *testing.T) {
	for _, test := range []struct {
		name, path, body, auth, upstreamPath, upstreamAuth, response string
		input, output                                                int64
		stream                                                       bool
	}{
		{"responses_json", "/v1/responses", `{"model":"a","input":"hello","max_output_tokens":20}`, "Authorization", "/responses", "Authorization", `{"object":"response","id":"resp_1","status":"completed","usage":{"input_tokens":7,"output_tokens":9}}`, 7, 9, false},
		{"responses_sse", "/v1/responses", `{"model":"a","input":"hello","stream":true}`, "Authorization", "/responses", "Authorization", "event: response.completed\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_2\",\"usage\":{\"input_tokens\":7,\"output_tokens\":9}}}\n\n", 7, 9, true},
		{"anthropic_json", "/v1/messages", `{"model":"a","messages":[{"role":"user","content":[{"type":"text","text":"hello"}]}],"max_tokens":20}`, "X-Api-Key", "/messages", "X-Api-Key", `{"type":"message","content":[],"usage":{"input_tokens":10,"cache_creation_input_tokens":3,"cache_read_input_tokens":4,"output_tokens":8}}`, 17, 8, false},
		{"anthropic_sse", "/v1/messages", `{"model":"a","messages":[{"role":"user","content":"hello"}],"stream":true}`, "X-Api-Key", "/messages", "X-Api-Key", "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":10,\"cache_creation_input_tokens\":3,\"cache_read_input_tokens\":4,\"output_tokens\":0}}}\n\nevent: message_delta\ndata: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":8}}\n\nevent: message_stop\ndata: {\"type\":\"message_stop\"}\n\n", 17, 8, true},
		{"gemini_json", "/v1beta/models/a:generateContent", `{"contents":[{"parts":[{"text":"hello"}]}]}`, "X-Goog-Api-Key", "/models/a:generateContent", "X-Goog-Api-Key", `{"candidates":[{"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":10,"candidatesTokenCount":5,"thoughtsTokenCount":7,"totalTokenCount":22}}`, 10, 12, false},
		{"gemini_sse", "/v1beta/models/a:streamGenerateContent", `{"contents":[{"parts":[{"text":"hello"}]}]}`, "X-Goog-Api-Key", "/models/a:streamGenerateContent", "X-Goog-Api-Key", "data: {\"usageMetadata\":{\"promptTokenCount\":10,\"totalTokenCount\":12}}\n\ndata: {\"candidates\":[{\"finishReason\":\"STOP\"}],\"usageMetadata\":{\"promptTokenCount\":10,\"totalTokenCount\":22}}\n\n", 10, 12, true},
	} {
		t.Run(test.name, func(t *testing.T) {
			up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path != test.upstreamPath {
					t.Errorf("path %s", r.URL.Path)
				}
				value := r.Header.Get(test.upstreamAuth)
				if !strings.Contains(value, "upstream-secret") || strings.Contains(value, "bc-client") {
					t.Error("wrong credentials")
				}
				var body map[string]any
				_ = json.NewDecoder(r.Body).Decode(&body)
				if strings.HasPrefix(test.name, "responses") && body["max_output_tokens"] != float64(20) {
					t.Error("missing Responses cap")
				}
				if strings.HasPrefix(test.name, "anthropic") && (body["max_tokens"] != float64(20) || r.Header.Get("anthropic-version") == "") {
					t.Error("missing Anthropic cap/version")
				}
				if strings.HasPrefix(test.name, "gemini") {
					if body["generationConfig"].(map[string]any)["maxOutputTokens"] != float64(20) {
						t.Error("missing Gemini cap")
					}
					if test.stream && r.URL.Query().Get("alt") != "sse" {
						t.Error("missing alt=sse")
					}
				}
				if test.stream {
					w.Header().Set("Content-Type", "text/event-stream")
				} else {
					w.Header().Set("Content-Type", "application/json")
				}
				_, _ = io.WriteString(w, test.response)
			}))
			defer up.Close()
			settled := make(chan settlement, 1)
			panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if strings.HasSuffix(r.URL.Path, "/reserve") {
					var params map[string]any
					_ = json.NewDecoder(r.Body).Decode(&params)
					if params["protocol"] != protocolPath(test.path) {
						t.Error("protocol not sent to quota service")
					}
					_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "upstream-secret", Max: 20})
				} else {
					var s settlement
					_ = json.NewDecoder(r.Body).Decode(&s)
					settled <- s
					_, _ = io.WriteString(w, `{}`)
				}
			}))
			defer panel.Close()
			g := &gateway{panel: panel.URL, spool: t.TempDir(), client: &http.Client{Timeout: time.Second}, callbacks: &http.Client{Timeout: time.Second}, slots: make(chan struct{}, 1)}
			req := httptest.NewRequest("POST", test.path, strings.NewReader(test.body))
			key := "bc-client"
			if test.auth == "Authorization" {
				key = "Bearer " + key
			}
			req.Header.Set(test.auth, key)
			w := httptest.NewRecorder()
			g.ServeHTTP(w, req)
			s := <-settled
			if w.Code != 200 || s.Status != "complete" || s.Input == nil || s.Output == nil || *s.Input != test.input || *s.Output != test.output {
				t.Fatalf("http=%d settlement=%+v", w.Code, s)
			}
			if strings.HasPrefix(test.name, "responses") && s.ResponseID == "" {
				t.Error("response ownership ID missing")
			}
		})
	}
}

func TestNativePayloadBoundaries(t *testing.T) {
	for _, test := range []struct{ path, body string }{
		{"/v1/responses", `{"model":"a","input":"x","background":true}`},
		{"/v1/responses", `{"model":"a","input":[{"type":"input_image","image_url":"https://example.com/image"}]}`},
		{"/v1beta/models/a:generateContent", `{"contents":[],"cachedContent":"cachedContents/secret"}`},
		{"/v1/messages", `{"model":"a","messages":[{"content":[{"type":"image"}]}]}`},
		{"/v1beta/models/a:generateContent", `{"contents":[{}],"generationConfig":{"candidateCount":2}}`},
	} {
		if _, err := decodeRequest(test.path, []byte(test.body)); err == nil {
			t.Errorf("accepted %s", test.body)
		}
	}
}

func TestCacheDetailsRemainDistinctAcrossProtocols(t *testing.T) {
	for _, test := range []struct {
		protocol, body     string
		read, write, input int64
	}{
		{"openai", `{"usage":{"prompt_tokens":100,"completion_tokens":2,"prompt_tokens_details":{"cached_tokens":80}}}`, 80, 0, 100},
		{"responses", `{"usage":{"input_tokens":100,"output_tokens":2,"input_tokens_details":{"cached_tokens":80}}}`, 80, 0, 100},
		{"anthropic", `{"usage":{"input_tokens":10,"output_tokens":2,"cache_read_input_tokens":80,"cache_creation_input_tokens":10}}`, 80, 10, 100},
		{"gemini", `{"usageMetadata":{"promptTokenCount":100,"totalTokenCount":102,"cachedContentTokenCount":80}}`, 80, 0, 100},
	} {
		meter := protocolUsage{protocol: test.protocol}
		meter.consume([]byte(test.body))
		var s settlement
		meter.apply(&s)
		if s.CacheRead == nil || *s.CacheRead != test.read || s.CacheWrite == nil || *s.CacheWrite != test.write || s.Input == nil || *s.Input != test.input {
			t.Fatalf("%s: %+v", test.protocol, s)
		}
	}
}
