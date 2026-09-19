package main

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"regexp"
	"strings"
)

type requestSpec struct {
	Protocol string
	Model    string
	Path     string
	Body     map[string]any
	Max      *int
	Stream   bool
	Previous string
}

var geminiPath = regexp.MustCompile(`^/v1beta/models/([A-Za-z0-9_.-]+):(generateContent|streamGenerateContent)$`)

func protocolPath(path string) string {
	switch path {
	case "/v1/chat/completions":
		return "openai"
	case "/v1/responses":
		return "responses"
	case "/v1/messages":
		return "anthropic"
	}
	if geminiPath.MatchString(path) {
		return "gemini"
	}
	return ""
}

func decodeRequest(path string, body []byte) (requestSpec, error) {
	spec := requestSpec{Protocol: protocolPath(path), Path: strings.TrimPrefix(path, "/v1")}
	if spec.Protocol == "" {
		return spec, errors.New("Unsupported endpoint")
	}
	var p map[string]any
	if json.Unmarshal(body, &p) != nil || p == nil {
		return spec, errors.New("Invalid JSON")
	}
	spec.Body = p
	if containsMedia(p) {
		return spec, errors.New("Only text and function tools are supported")
	}
	if p["conversation"] != nil || p["cachedContent"] != nil || p["background"] == true {
		return spec, errors.New("External conversation/cache and background requests cannot be metered here")
	}
	if v, exists := p["stream"]; exists {
		var ok bool
		spec.Stream, ok = v.(bool)
		if !ok {
			return spec, errors.New("Invalid stream flag")
		}
	}
	spec.Model, _ = p["model"].(string)
	outputFields := []string{"max_tokens", "max_completion_tokens"}
	limits := p
	switch spec.Protocol {
	case "openai", "anthropic":
		messages, ok := p["messages"].([]any)
		if !ok || len(messages) < 1 || len(messages) > 256 {
			return spec, errors.New("1-256 messages required")
		}
		for _, value := range messages {
			if _, ok := value.(map[string]any); !ok {
				return spec, errors.New("Invalid message")
			}
		}
		if n, ok := p["n"]; ok && n != float64(1) {
			return spec, errors.New("n must be 1")
		}
		if spec.Protocol == "anthropic" {
			outputFields = []string{"max_tokens"}
		}
	case "responses":
		switch input := p["input"].(type) {
		case string:
			if input == "" {
				return spec, errors.New("Input required")
			}
		case []any:
			if len(input) > 256 {
				return spec, errors.New("Too many input items")
			}
		default:
			return spec, errors.New("Responses input must be text or items")
		}
		if p["previous_response_id"] != nil {
			var ok bool
			spec.Previous, ok = p["previous_response_id"].(string)
			if !ok || len(spec.Previous) > 200 {
				return spec, errors.New("Invalid previous response ID")
			}
		}
		outputFields = []string{"max_output_tokens"}
	case "gemini":
		match := geminiPath.FindStringSubmatch(path)
		spec.Model, spec.Stream = match[1], match[2] == "streamGenerateContent"
		spec.Path = "/models/" + url.PathEscape(spec.Model) + ":" + match[2]
		if spec.Stream {
			spec.Path += "?alt=sse"
		}
		contents, ok := p["contents"].([]any)
		if !ok || len(contents) < 1 || len(contents) > 256 {
			return spec, errors.New("1-256 contents required")
		}
		if value, exists := p["generationConfig"]; exists {
			limits, ok = value.(map[string]any)
			if !ok {
				return spec, errors.New("Invalid generationConfig")
			}
		} else {
			limits = map[string]any{}
			p["generationConfig"] = limits
		}
		if n, ok := limits["candidateCount"]; ok && n != float64(1) {
			return spec, errors.New("candidateCount must be 1")
		}
		outputFields = []string{"maxOutputTokens"}
	}
	if spec.Model == "" || len(spec.Model) > 150 {
		return spec, errors.New("Model required")
	}
	for _, field := range outputFields {
		if value, exists := limits[field]; exists {
			n, ok := value.(float64)
			if !ok || n < 1 || n > 131072 || n != float64(int(n)) || spec.Max != nil {
				return spec, errors.New("Invalid or duplicate output limit")
			}
			max := int(n)
			spec.Max = &max
		}
	}
	delete(p, "user")
	return spec, nil
}

func containsMedia(value any) bool {
	switch v := value.(type) {
	case []any:
		for _, item := range v {
			if containsMedia(item) {
				return true
			}
		}
	case map[string]any:
		for key, item := range v {
			switch key {
			case "audio", "modalities", "inlineData", "inline_data", "fileData", "file_data", "image_url":
				return true
			}
			if key == "type" {
				switch item {
				case "input_image", "input_file", "image", "image_url", "document", "input_audio":
					return true
				}
			}
			if containsMedia(item) {
				return true
			}
		}
	}
	return false
}

func (s requestSpec) capOutput(max int) {
	switch s.Protocol {
	case "responses", "responses_ws":
		s.Body["max_output_tokens"] = max
	case "gemini":
		s.Body["generationConfig"].(map[string]any)["maxOutputTokens"] = max
	default:
		if _, ok := s.Body["max_completion_tokens"]; ok {
			s.Body["max_completion_tokens"] = max
		} else {
			s.Body["max_tokens"] = max
		}
	}
	if s.Stream && s.Protocol == "openai" {
		s.Body["stream_options"] = map[string]bool{"include_usage": true}
	}
}

func setUpstreamHeaders(req *http.Request, protocol, key string, incoming http.Header) {
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	switch protocol {
	case "anthropic":
		req.Header.Set("X-Api-Key", key)
		version := incoming.Get("anthropic-version")
		if version == "" {
			version = "2023-06-01"
		}
		req.Header.Set("anthropic-version", version)
		if beta := incoming.Get("anthropic-beta"); beta != "" && len(beta) <= 1000 {
			req.Header.Set("anthropic-beta", beta)
		}
	case "gemini":
		req.Header.Set("X-Goog-Api-Key", key)
	default:
		req.Header.Set("Authorization", "Bearer "+key)
	}
}

func clientKey(r *http.Request) string {
	if auth := r.Header.Get("Authorization"); strings.HasPrefix(auth, "Bearer ") {
		return strings.TrimPrefix(auth, "Bearer ")
	}
	if key := r.Header.Get("X-Api-Key"); key != "" {
		return key
	}
	return r.Header.Get("X-Goog-Api-Key")
}

type protocolUsage struct {
	protocol              string
	input, output         *int64
	cacheRead, cacheWrite *int64
	done                  bool
	failed                bool
	responseID            string
}

func intToken(value any) *int64 {
	n, ok := value.(float64)
	if !ok || n < 0 || n > 1000000000 || n != float64(int64(n)) {
		return nil
	}
	i := int64(n)
	return &i
}
func (u *protocolUsage) consume(data []byte) {
	if strings.TrimSpace(string(data)) == "[DONE]" {
		u.done = true
		return
	}
	var p map[string]any
	if json.Unmarshal(data, &p) != nil {
		return
	}
	if p["error"] != nil || p["type"] == "error" {
		u.failed = true
	}
	switch u.protocol {
	case "responses", "responses_ws":
		kind, _ := p["type"].(string)
		if kind == "response.completed" || kind == "response.incomplete" || kind == "response.failed" {
			u.done = true
			u.failed = kind != "response.completed"
		}
		if response, ok := p["response"].(map[string]any); ok {
			p = response
		}
		if p["object"] == "response" || p["status"] == "completed" || kind == "response.completed" {
			u.responseID, _ = p["id"].(string)
		}
		if p["status"] == "failed" || p["status"] == "incomplete" {
			u.failed = true
		}
		if v, ok := p["usage"].(map[string]any); ok {
			u.input, u.output = intToken(v["input_tokens"]), intToken(v["output_tokens"])
			zero := int64(0)
			u.cacheWrite = &zero
			if details, ok := v["input_tokens_details"].(map[string]any); ok {
				if cached := intToken(details["cached_tokens"]); cached != nil {
					u.cacheRead = cached
				}
			}
		}
	case "anthropic":
		if p["type"] == "message_stop" {
			u.done = true
		}
		if message, ok := p["message"].(map[string]any); ok {
			p = message
		}
		if v, ok := p["usage"].(map[string]any); ok {
			if input := intToken(v["input_tokens"]); input != nil {
				for _, field := range []string{"cache_creation_input_tokens", "cache_read_input_tokens"} {
					if cached := intToken(v[field]); cached != nil {
						*input += *cached
					}
				}
				u.input = input
			}
			if output := intToken(v["output_tokens"]); output != nil {
				u.output = output
			}
			if cached := intToken(v["cache_read_input_tokens"]); cached != nil {
				u.cacheRead = cached
			}
			if cached := intToken(v["cache_creation_input_tokens"]); cached != nil {
				u.cacheWrite = cached
			}
		}
	case "gemini":
		if candidates, ok := p["candidates"].([]any); ok {
			for _, candidate := range candidates {
				if c, ok := candidate.(map[string]any); ok && c["finishReason"] != nil {
					u.done = true
				}
			}
		}
		if p["promptFeedback"] != nil && p["candidates"] == nil {
			u.done = true
		}
		if v, ok := p["usageMetadata"].(map[string]any); ok {
			zero := int64(0)
			u.cacheWrite = &zero
			if cached := intToken(v["cachedContentTokenCount"]); cached != nil {
				u.cacheRead = cached
			}
			u.input = intToken(v["promptTokenCount"])
			if total := intToken(v["totalTokenCount"]); total != nil && u.input != nil && *total >= *u.input {
				output := *total - *u.input
				u.output = &output
			} else if output := intToken(v["candidatesTokenCount"]); output != nil {
				if thoughts := intToken(v["thoughtsTokenCount"]); thoughts != nil {
					*output += *thoughts
				}
				u.output = output
			}
		}
	default:
		if v, ok := p["usage"].(map[string]any); ok {
			u.input, u.output = intToken(v["prompt_tokens"]), intToken(v["completion_tokens"])
			zero := int64(0)
			u.cacheWrite = &zero
			if details, ok := v["prompt_tokens_details"].(map[string]any); ok {
				if cached := intToken(details["cached_tokens"]); cached != nil {
					u.cacheRead = cached
				}
			}
		}
	}
}
func (u *protocolUsage) apply(s *settlement) {
	s.Input, s.Output, s.ResponseID = u.input, u.output, u.responseID
	s.CacheRead, s.CacheWrite = u.cacheRead, u.cacheWrite
}
