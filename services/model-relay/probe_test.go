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

func TestQualityProbeUsesConfiguredProtocolAndDoesNotExposeSecret(t *testing.T) {
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/chat/completions" || r.Header.Get("Authorization") != "Bearer private-key" {
			t.Error("unexpected upstream request")
		}
		_, _ = io.WriteString(w, `{"choices":[{"message":{"content":"{\"answer\":\"7\"}"}}],"usage":{"prompt_tokens":10,"completion_tokens":5}}`)
	}))
	defer up.Close()
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "private-key", Enabled: true, Protocols: []string{"openai"}, Models: []string{"a"}})
	}))
	defer panel.Close()
	g := &gateway{panel: panel.URL, secret: strings.Repeat("s", 32), client: &http.Client{Timeout: time.Second}, callbacks: &http.Client{Timeout: time.Second}, slots: make(chan struct{}, 1)}
	for _, protocol := range []string{"openai", "anthropic"} {
		body, _ := json.Marshal(map[string]any{"id": 1, "protocol": protocol, "model": "a", "prompt": "test", "max_output": 32})
		req := httptest.NewRequest("POST", "/admin/probe", strings.NewReader(string(body)))
		req.Header.Set("X-Relay-Secret", g.secret)
		w := httptest.NewRecorder()
		g.ServeHTTP(w, req)
		if protocol == "anthropic" {
			if w.Code != 422 {
				t.Fatal(w.Code)
			}
			continue
		}
		var result map[string]any
		_ = json.Unmarshal(w.Body.Bytes(), &result)
		if result["ok"] != true || result["tokens"] != float64(15) || result["text"] != `{"answer":"7"}` || strings.Contains(w.Body.String(), "private-key") {
			t.Fatal(w.Body.String())
		}
	}
	w := httptest.NewRecorder()
	g.ServeHTTP(w, httptest.NewRequest("POST", "/admin/probe", strings.NewReader(`{}`)))
	if w.Code != 403 {
		t.Fatal("probe without secret accepted")
	}
}
