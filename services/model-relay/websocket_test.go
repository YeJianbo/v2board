package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func TestResponsesWSMetersEveryTurnAndRechecksRevocation(t *testing.T) {
	var calls atomic.Int32
	started, finish := make(chan struct{}, 1), make(chan struct{})
	var releaseOnce sync.Once
	release := func() { releaseOnce.Do(func() { close(finish) }) }
	defer release()
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer upstream-key" {
			t.Error("wrong upstream credentials")
		}
		u := websocket.Upgrader{}
		conn, err := u.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer conn.Close()
		for {
			var body map[string]any
			if conn.ReadJSON(&body) != nil {
				return
			}
			calls.Add(1)
			started <- struct{}{}
			if body["type"] != "response.create" || body["max_output_tokens"] != float64(30) {
				t.Error("output limit or event lost")
			}
			<-finish
			_ = conn.WriteJSON(map[string]any{"type": "response.completed", "response": map[string]any{"id": "resp_test", "usage": map[string]int{"input_tokens": 12, "output_tokens": 3}}})
		}
	}))
	defer up.Close()
	var revoked atomic.Bool
	settlements := make(chan settlement, 2)
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "/connect"):
			_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "upstream-key"})
		case strings.HasSuffix(r.URL.Path, "/reserve"):
			if revoked.Load() {
				w.WriteHeader(401)
				return
			}
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			if body["protocol"] != "responses_ws" {
				t.Error("wrong protocol")
			}
			_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "upstream-key", Max: 30})
		default:
			var result settlement
			_ = json.NewDecoder(r.Body).Decode(&result)
			settlements <- result
			_, _ = w.Write([]byte(`{}`))
		}
	}))
	defer panel.Close()
	g := &gateway{panel: panel.URL, callbacks: &http.Client{Timeout: time.Second}, spool: t.TempDir(), slots: make(chan struct{}, 2), wsDial: websocket.DefaultDialer.DialContext}
	server := httptest.NewServer(g)
	defer server.Close()
	conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"/v1/responses", http.Header{"Authorization": []string{"Bearer bc-client"}})
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
	body := map[string]any{"type": "response.create", "model": "a", "input": "hello"}
	if err := conn.WriteJSON(body); err != nil {
		t.Fatal(err)
	}
	var event map[string]any
	<-started
	_ = conn.WriteJSON(map[string]any{"type": "response.cancel", "response_id": "another-tenant-response"})
	if err := conn.ReadJSON(&event); err != nil {
		release()
		t.Fatal(err)
	}
	if event["status"] != float64(422) {
		release()
		t.Fatalf("foreign cancellation accepted: %v", event)
	}
	release()
	if err := conn.ReadJSON(&event); err != nil {
		t.Fatal(err)
	}
	if event["type"] != "response.completed" {
		t.Fatalf("%v", event)
	}
	result := <-settlements
	if result.Status != "complete" || result.Input == nil || *result.Input != 12 || *result.Output != 3 || result.ResponseID != "resp_test" {
		t.Fatalf("%+v", result)
	}
	revoked.Store(true)
	_ = conn.WriteJSON(body)
	if err := conn.ReadJSON(&event); err != nil {
		t.Fatal(err)
	}
	if event["type"] != "error" || event["status"] != float64(401) || calls.Load() != 1 {
		t.Fatalf("revocation bypass: %v", event)
	}
}
