package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"google.golang.org/genproto/googleapis/rpc/errdetails"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials"
	_ "google.golang.org/grpc/encoding/gzip"
	"google.golang.org/grpc/health"
	healthpb "google.golang.org/grpc/health/grpc_health_v1"
	"google.golang.org/grpc/metadata"
	"google.golang.org/grpc/peer"
	"google.golang.org/grpc/status"

	"hypervel.dev/components/grpc-interop/testingpb"
)

type testService struct {
	testingpb.UnimplementedTestServiceServer
	connections *connectionRegistry
}

func (service *testService) Unary(ctx context.Context, request *testingpb.TestRequest) (*testingpb.TestReply, error) {
	if err := setResponseMetadata(ctx); err != nil {
		return nil, status.Error(codes.Internal, err.Error())
	}

	value := request.GetValue()

	switch {
	case value == "error:not-found":
		return nil, status.Error(codes.NotFound, "The requested test value was not found.")
	case value == "error:rich":
		richStatus, err := status.New(codes.InvalidArgument, "The test value is invalid.").WithDetails(
			&errdetails.ErrorInfo{
				Reason: "INVALID_TEST_VALUE",
				Domain: "hypervel.dev",
				Metadata: map[string]string{
					"value": value,
				},
			},
		)
		if err != nil {
			return nil, status.Error(codes.Internal, err.Error())
		}

		return nil, richStatus.Err()
	case strings.HasPrefix(value, "delay:"):
		delay, err := time.ParseDuration(strings.TrimPrefix(value, "delay:"))
		if err != nil {
			return nil, status.Error(codes.InvalidArgument, "The delay is invalid.")
		}

		select {
		case <-time.After(delay):
			return &testingpb.TestReply{Value: "delayed"}, nil
		case <-ctx.Done():
			return nil, status.FromContextError(ctx.Err()).Err()
		}
	case strings.HasPrefix(value, "gzip:"):
		if err := grpc.SetSendCompressor(ctx, "gzip"); err != nil {
			return nil, status.Error(codes.Internal, err.Error())
		}

		return &testingpb.TestReply{Value: "unary:" + strings.TrimPrefix(value, "gzip:")}, nil
	case value == "retry:merged":
		attempt := previousAttempts(ctx)
		if attempt == 0 {
			if err := grpc.SendHeader(ctx, metadata.Pairs("x-retry-header", "sent")); err != nil {
				return nil, status.Error(codes.Internal, err.Error())
			}

			return nil, status.Error(codes.Unavailable, "Retry the uncommitted call.")
		}

		return &testingpb.TestReply{Value: fmt.Sprintf("retried:%d", attempt)}, nil
	case value == "disconnect":
		remotePeer, ok := peer.FromContext(ctx)
		if !ok || !service.connections.close(remotePeer.Addr.String()) {
			return nil, status.Error(codes.Internal, "The peer connection could not be closed.")
		}

		<-ctx.Done()

		return nil, status.FromContextError(ctx.Err()).Err()
	default:
		return &testingpb.TestReply{Value: "unary:" + value}, nil
	}
}

func (service *testService) ServerStream(request *testingpb.TestRequest, stream grpc.ServerStreamingServer[testingpb.TestReply]) error {
	if err := setResponseMetadata(stream.Context()); err != nil {
		return status.Error(codes.Internal, err.Error())
	}

	value := request.GetValue()

	if strings.HasPrefix(value, "gzip:") {
		if err := grpc.SetSendCompressor(stream.Context(), "gzip"); err != nil {
			return status.Error(codes.Internal, err.Error())
		}

		value = strings.TrimPrefix(value, "gzip:")
	}

	switch value {
	case "empty":
		return nil
	case "pre-error":
		return status.Error(codes.Unavailable, "The stream failed before its first response.")
	case "partial-error":
		if err := stream.Send(&testingpb.TestReply{Value: "stream:partial:1"}); err != nil {
			return err
		}

		return status.Error(codes.NotFound, "The stream failed after a response.")
	}

	for index := 1; index <= 3; index++ {
		if err := stream.Send(&testingpb.TestReply{
			Value: fmt.Sprintf("stream:%s:%d", value, index),
		}); err != nil {
			return err
		}
	}

	return nil
}

func (service *testService) ClientStream(stream grpc.ClientStreamingServer[testingpb.TestRequest, testingpb.TestReply]) error {
	if err := setResponseMetadata(stream.Context()); err != nil {
		return status.Error(codes.Internal, err.Error())
	}

	values := make([]string, 0)

	for {
		request, err := stream.Recv()
		if errors.Is(err, io.EOF) {
			return stream.SendAndClose(&testingpb.TestReply{
				Value: "client:" + strings.Join(values, ","),
			})
		}
		if err != nil {
			return err
		}

		values = append(values, request.GetValue())
	}
}

func (service *testService) BidiStream(stream grpc.BidiStreamingServer[testingpb.TestRequest, testingpb.TestReply]) error {
	if err := setResponseMetadata(stream.Context()); err != nil {
		return status.Error(codes.Internal, err.Error())
	}

	for {
		request, err := stream.Recv()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}

		if err := stream.Send(&testingpb.TestReply{Value: "bidi:" + request.GetValue()}); err != nil {
			return err
		}
	}
}

func setResponseMetadata(ctx context.Context) error {
	incoming, _ := metadata.FromIncomingContext(ctx)
	header := metadata.Pairs("x-test-peer", "grpc-go")
	trailer := metadata.Pairs("x-test-trailer", "grpc-go")

	for _, value := range incoming.Get("x-echo") {
		header.Append("x-echo", value)
	}
	for _, value := range incoming.Get("echo-bin") {
		trailer.Append("echo-bin", value)
	}

	if err := grpc.SetHeader(ctx, header); err != nil {
		return err
	}

	return grpc.SetTrailer(ctx, trailer)
}

func previousAttempts(ctx context.Context) int {
	incoming, _ := metadata.FromIncomingContext(ctx)
	values := incoming.Get("grpc-previous-rpc-attempts")
	if len(values) != 1 {
		return 0
	}

	attempts, err := strconv.Atoi(values[0])
	if err != nil || attempts < 0 {
		return 0
	}

	return attempts
}

type connectionRegistry struct {
	mutex       sync.Mutex
	connections map[string]*trackedConnection
}

func newConnectionRegistry() *connectionRegistry {
	return &connectionRegistry{connections: make(map[string]*trackedConnection)}
}

func (registry *connectionRegistry) add(connection *trackedConnection) {
	registry.mutex.Lock()
	defer registry.mutex.Unlock()

	registry.connections[connection.RemoteAddr().String()] = connection
}

func (registry *connectionRegistry) remove(connection *trackedConnection) {
	registry.mutex.Lock()
	defer registry.mutex.Unlock()

	key := connection.RemoteAddr().String()
	if registry.connections[key] == connection {
		delete(registry.connections, key)
	}
}

func (registry *connectionRegistry) close(remoteAddress string) bool {
	registry.mutex.Lock()
	connection := registry.connections[remoteAddress]
	registry.mutex.Unlock()

	if connection == nil {
		return false
	}

	return connection.Close() == nil
}

type trackingListener struct {
	net.Listener
	registry *connectionRegistry
}

func (listener *trackingListener) Accept() (net.Conn, error) {
	connection, err := listener.Listener.Accept()
	if err != nil {
		return nil, err
	}

	tracked := &trackedConnection{
		Conn:     connection,
		registry: listener.registry,
	}
	listener.registry.add(tracked)

	return tracked, nil
}

type trackedConnection struct {
	net.Conn
	registry *connectionRegistry
	once     sync.Once
	error    error
}

func (connection *trackedConnection) Close() error {
	connection.once.Do(func() {
		connection.registry.remove(connection)
		connection.error = connection.Conn.Close()
	})

	return connection.error
}

func main() {
	port := os.Getenv("GRPC_GO_SERVER_PORT")
	if port == "" {
		port = "19521"
	}
	listener, err := net.Listen("tcp", "127.0.0.1:"+port)
	if err != nil {
		log.Fatal(err)
	}

	connections := newConnectionRegistry()
	trackedListener := &trackingListener{Listener: listener, registry: connections}
	serverOptions := make([]grpc.ServerOption, 0, 1)
	certificate := os.Getenv("GRPC_GO_SERVER_CERT")
	privateKey := os.Getenv("GRPC_GO_SERVER_KEY")

	if certificate != "" || privateKey != "" {
		if certificate == "" || privateKey == "" {
			log.Fatal("GRPC_GO_SERVER_CERT and GRPC_GO_SERVER_KEY must be supplied together")
		}

		transportCredentials, err := credentials.NewServerTLSFromFile(certificate, privateKey)
		if err != nil {
			log.Fatal(err)
		}

		serverOptions = append(serverOptions, grpc.Creds(transportCredentials))
	}

	server := grpc.NewServer(serverOptions...)
	testingpb.RegisterTestServiceServer(server, &testService{connections: connections})
	healthServer := health.NewServer()
	healthServer.SetServingStatus("", healthpb.HealthCheckResponse_SERVING)
	healthServer.SetServingStatus("testing", healthpb.HealthCheckResponse_NOT_SERVING)
	healthpb.RegisterHealthServer(server, healthServer)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	go func() {
		<-ctx.Done()
		server.GracefulStop()
	}()

	log.Printf("grpc-go test server listening on 127.0.0.1:%s", port)
	if err := server.Serve(trackedListener); err != nil {
		log.Fatal(err)
	}
}
