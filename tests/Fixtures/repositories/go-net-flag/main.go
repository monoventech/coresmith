package main

import (
	"flag"
	"net/http"
)

func main() {
	addr := flag.String("addr", ":8080", "address to listen on")
	flag.Parse()
	http.ListenAndServe(*addr, http.NotFoundHandler())
}
