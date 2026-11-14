package main

import (
	"net"
	"os"

	"google.golang.org/grpc"
)

func main() {
	lis, err := net.Listen("tcp", "127.0.0.1:"+os.Getenv("PORT"))
	if err != nil {
		panic(err)
	}
	s := grpc.NewServer()
	s.Serve(lis)
}
