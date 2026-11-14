package main

import (
	"net/http"
	"os"

	"example.com/util"
)

func main() {
	http.HandleFunc("/", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte(util.Greeting())) })
	http.ListenAndServe(":"+os.Getenv("PORT"), nil)
}
