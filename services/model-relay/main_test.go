package main

import (
	"bufio"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestPrivateUpstreamRejected(t *testing.T) {
	for _, address := range []string{"127.0.0.1:443", "10.0.0.1:443", "[::1]:443", "169.254.169.254:80"} {
		if c, err := publicDial(context.Background(), "tcp", address); err == nil {
			c.Close()
			t.Fatalf("private address accepted: %s", address)
		}
	}
}

func TestSSEFlushesBeforeCompletion(t *testing.T) {
	finish := make(chan struct{})
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		_, _ = io.WriteString(w, "data: {\"choices\":[]}\n\n")
		w.(http.Flusher).Flush()
		<-finish
		_, _ = io.WriteString(w, "data: {\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":3}}\n\ndata: [DONE]\n\n")
	}))
	defer up.Close()
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/reserve") {
			_ = json.NewEncoder(w).Encode(reservation{URL: up.URL, Key: "test", Max: 10})
		} else {
			_, _ = io.WriteString(w, `{}`)
		}
	}))
	defer panel.Close()
	g := &gateway{panel: panel.URL, secret: "test", spool: t.TempDir(), client: &http.Client{Timeout: 3 * time.Second}, callbacks: &http.Client{Timeout: time.Second}, slots: make(chan struct{}, 2)}
	server := httptest.NewServer(g)
	defer server.Close()
	req, _ := http.NewRequest("POST", server.URL+"/v1/chat/completions", strings.NewReader(`{"model":"test","messages":[{"role":"user","content":"hello"}],"stream":true}`))
	req.Header.Set("Authorization", "Bearer bc-test")
	response, err := (&http.Client{Timeout: 2 * time.Second}).Do(req)
	if err != nil {
		close(finish)
		t.Fatal(err)
	}
	line, err := bufio.NewReader(response.Body).ReadString('\n')
	close(finish)
	if err != nil || !strings.Contains(line, "data:") {
		t.Fatalf("first event not flushed: %s %v", line, err)
	}
	_, _ = io.Copy(io.Discard, response.Body)
	response.Body.Close()
}

func TestCloudflareDiagnosisDoesNotExposeHTML(t *testing.T) {
	resp := &http.Response{StatusCode: 403, Header: http.Header{"Server": []string{"cloudflare"}}, Body: io.NopCloser(strings.NewReader("Attention Required! PRIVATE"))}
	if code := upstreamError(resp); code != "cloudflare_blocked" {
		t.Fatal(code)
	}
}

func TestAdminTestRequiresSeparateSecret(t *testing.T) {
	g := &gateway{secret: strings.Repeat("s", 32)}
	w := httptest.NewRecorder()
	g.ServeHTTP(w, httptest.NewRequest("POST", "/admin/test", strings.NewReader(`{"id":1}`)))
	if w.Code != 403 {
		t.Fatal(w.Code)
	}
}

func TestPayloadLimits(t *testing.T) {
	for _, body := range []string{
		`{}`, `{"model":"a","messages":[{"content":[{"type":"image_url"}]}]}`,
		`{"model":"a","messages":[{"content":"x"}],"n":2}`,
		`{"model":"a","messages":[{"content":"x"}],"max_tokens":1.5}`,
		`{"model":"a","messages":[{"content":"x"}],"max_tokens":5,"max_completion_tokens":6}`,
	} {
		if _, _, _, _, err := validatePayload([]byte(body)); err == nil {
			t.Fatalf("accepted %s", body)
		}
	}
	if _, _, _, stream, err := validatePayload([]byte(`{"model":"a","messages":[{"role":"user","content":"hello"}],"stream":true}`)); err != nil || !stream {
		t.Fatal(err)
	}
}

func TestProxyUsageAndNoCredentialLeak(t *testing.T) {
	for _, stream := range []bool{false, true} {
		t.Run(map[bool]string{true: "stream", false: "json"}[stream], func(t *testing.T) {
			var got settlement
			upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.Header.Get("Authorization") != "Bearer upstream-secret" {
					t.Error("wrong upstream key")
				}
				var body map[string]any
				_ = json.NewDecoder(r.Body).Decode(&body)
				if body["max_tokens"] != float64(99) {
					t.Error("output cap missing")
				}
				if stream {
					if body["stream_options"].(map[string]any)["include_usage"] != true {
						t.Error("usage not requested")
					}
					w.Header().Set("Content-Type", "text/event-stream")
					_, _ = io.WriteString(w, "data: {\"choices\":[{\"delta\":{\"content\":\"hello\"}}]}\n\ndata: {\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":20}}\n\ndata: [DONE]\n\n")
				} else {
					_, _ = io.WriteString(w, `{"choices":[],"usage":{"prompt_tokens":10,"completion_tokens":20}}`)
				}
			}))
			defer upstream.Close()
			panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.Header.Get("X-Relay-Secret") != "internal-secret" {
					t.Error("missing callback secret")
				}
				if strings.HasSuffix(r.URL.Path, "/reserve") {
					_ = json.NewEncoder(w).Encode(reservation{URL: upstream.URL, Key: "upstream-secret", Max: 99})
				} else {
					_ = json.NewDecoder(r.Body).Decode(&got)
					_, _ = w.Write([]byte(`{}`))
				}
			}))
			defer panel.Close()
			g := &gateway{panel: panel.URL, secret: "internal-secret", spool: t.TempDir(), client: &http.Client{Timeout: time.Second}, callbacks: &http.Client{Timeout: time.Second}, slots: make(chan struct{}, 2)}
			body := `{"model":"a","messages":[{"role":"user","content":"hello"}],"stream":` + map[bool]string{true: "true", false: "false"}[stream] + `}`
			req := httptest.NewRequest("POST", "/v1/chat/completions", strings.NewReader(body))
			req.Header.Set("Authorization", "Bearer bc-machine")
			w := httptest.NewRecorder()
			g.ServeHTTP(w, req)
			if w.Code != 200 || got.Input == nil || *got.Input != 10 || *got.Output != 20 || got.Status != "complete" {
				t.Fatalf("status=%d settlement=%+v", w.Code, got)
			}
			if strings.Contains(w.Body.String(), "secret") {
				t.Fatal("secret leaked")
			}
		})
	}
}

func TestUnauthenticated(t *testing.T) {
	g := &gateway{}
	w := httptest.NewRecorder()
	g.ServeHTTP(w, httptest.NewRequest("GET", "/v1/models", nil))
	if w.Code != 401 {
		t.Fatal(w.Code)
	}
}

func TestJSONReadFailureDiagnosis(t *testing.T) {
	for _, test := range []struct {
		name  string
		code  string
		stall bool
	}{
		{name: "body_timeout", code: "upstream_network", stall: true},
		{name: "malformed_json", code: "invalid_response"},
	} {
		t.Run(test.name, func(t *testing.T) {
			upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				_, _ = io.WriteString(w, "private-incomplete-response")
				if test.stall {
					w.(http.Flusher).Flush()
					<-r.Context().Done()
				}
			}))
			defer upstream.Close()
			settlements := make(chan settlement, 1)
			panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if strings.HasSuffix(r.URL.Path, "/reserve") {
					_ = json.NewEncoder(w).Encode(reservation{URL: upstream.URL, Key: "test", Max: 10})
					return
				}
				var result settlement
				_ = json.NewDecoder(r.Body).Decode(&result)
				settlements <- result
				_, _ = io.WriteString(w, `{}`)
			}))
			defer panel.Close()
			g := &gateway{panel: panel.URL, spool: t.TempDir(), client: &http.Client{Timeout: 200 * time.Millisecond},
				callbacks: &http.Client{Timeout: time.Second}, slots: make(chan struct{}, 1)}
			req := httptest.NewRequest("POST", "/v1/chat/completions", strings.NewReader(`{"model":"test","messages":[{"role":"user","content":"hello"}]}`))
			req.Header.Set("Authorization", "Bearer bc-test")
			response := httptest.NewRecorder()
			g.ServeHTTP(response, req)
			result := <-settlements
			if response.Code != 502 || result.Error != test.code || result.Status != "unknown" || result.HTTP != 200 || result.Input != nil || result.Output != nil {
				t.Fatalf("http=%d settlement=%+v", response.Code, result)
			}
			if strings.Contains(response.Body.String(), "private-incomplete-response") {
				t.Fatal("upstream response leaked")
			}
		})
	}
}
