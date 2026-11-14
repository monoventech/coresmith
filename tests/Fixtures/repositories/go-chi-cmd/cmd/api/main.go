package main

import (
	"net/http"
	"os"

	"github.com/go-chi/chi/v5"
)

func main() {
	r := chi.NewRouter()
	r.Get("/", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte("shop")) })
	http.ListenAndServe(":"+os.Getenv("PORT"), r)
}
