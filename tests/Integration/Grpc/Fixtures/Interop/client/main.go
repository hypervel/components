package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"reflect"
	"time"

	"google.golang.org/genproto/googleapis/rpc/errdetails"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/credentials"
	"google.golang.org/grpc/credentials/insecure"
	"google.golang.org/grpc/encoding/gzip"
	healthpb "google.golang.org/grpc/health/grpc_health_v1"
	"google.golang.org/grpc/metadata"
	"google.golang.org/grpc/status"

	"hypervel.dev/components/grpc-interop/testingpb"
)

func main() {
	target := os.Getenv("GRPC_GO_CLIENT_TARGET")
	if target == "" {
		target = "127.0.0.1:19520"
	}

	transportCredentials := insecure.NewCredentials()
	if caFile := os.Getenv("GRPC_GO_CLIENT_CA"); caFile != "" {
		serverName := os.Getenv("GRPC_GO_CLIENT_SERVER_NAME")
		if serverName == "" {
			serverName = "localhost"
		}

		var err error
		transportCredentials, err = credentials.NewClientTLSFromFile(caFile, serverName)
		if err != nil {
			log.Fatal(err)
		}
	}

	connection, err := grpc.NewClient(
		target,
		grpc.WithTransportCredentials(transportCredentials),
	)
	if err != nil {
		log.Fatal(err)
	}
	defer connection.Close()

	if err := verifyTestService(testingpb.NewTestServiceClient(connection)); err != nil {
		log.Fatal(err)
	}
	if err := verifyHealthService(healthpb.NewHealthClient(connection)); err != nil {
		log.Fatal(err)
	}

	log.Printf("Hypervel gRPC interop checks passed against %s", target)
}

func verifyTestService(client testingpb.TestServiceClient) error {
	if err := verifyUnary(client); err != nil {
		return err
	}
	if err := verifyServerStreaming(client); err != nil {
		return err
	}
	if err := verifyErrors(client); err != nil {
		return err
	}

	return verifyDeadline(client)
}

func verifyUnary(client testingpb.TestServiceClient) error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	ctx = metadata.NewOutgoingContext(ctx, metadata.MD{
		"x-echo":   {"one", "two"},
		"echo-bin": {string([]byte{0x00, 0x01})},
	})
	var header metadata.MD
	var trailer metadata.MD
	reply, err := client.Unary(
		ctx,
		&testingpb.TestRequest{Value: "hello"},
		grpc.Header(&header),
		grpc.Trailer(&trailer),
		grpc.UseCompressor(gzip.Name),
	)
	if err != nil {
		return fmt.Errorf("unary call failed: %w", err)
	}
	if reply.GetValue() != "unary:hello" {
		return fmt.Errorf("unexpected unary reply %q", reply.GetValue())
	}
	if !reflect.DeepEqual(header.Get("x-test-peer"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected unary peer metadata: %v", header)
	}
	// Swoole exposes only the final independently repeated inbound field.
	if !reflect.DeepEqual(header.Get("x-echo"), []string{"two"}) {
		return fmt.Errorf("unexpected echoed ASCII metadata: %v", header)
	}
	if !reflect.DeepEqual(trailer.Get("x-test-trailer"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected unary trailers: %v", trailer)
	}
	if !reflect.DeepEqual(trailer.Get("echo-bin"), []string{string([]byte{0x00, 0x01})}) {
		return fmt.Errorf("unexpected echoed binary metadata: %v", trailer)
	}

	return nil
}

func verifyServerStreaming(client testingpb.TestServiceClient) error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	stream, err := client.ServerStream(ctx, &testingpb.TestRequest{Value: "hello"})
	if err != nil {
		return fmt.Errorf("server-streaming call failed: %w", err)
	}
	header, err := stream.Header()
	if err != nil {
		return fmt.Errorf("server-streaming headers failed: %w", err)
	}
	if !reflect.DeepEqual(header.Get("x-test-peer"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected server-streaming metadata: %v", header)
	}

	values := make([]string, 0, 3)
	for {
		reply, receiveError := stream.Recv()
		if errors.Is(receiveError, io.EOF) {
			break
		}
		if receiveError != nil {
			return fmt.Errorf("server-streaming receive failed: %w", receiveError)
		}

		values = append(values, reply.GetValue())
	}
	if !reflect.DeepEqual(values, []string{
		"stream:hello:1",
		"stream:hello:2",
		"stream:hello:3",
	}) {
		return fmt.Errorf("unexpected server-streaming replies: %v", values)
	}
	if !reflect.DeepEqual(stream.Trailer().Get("x-test-trailer"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected server-streaming trailers: %v", stream.Trailer())
	}

	partial, err := client.ServerStream(ctx, &testingpb.TestRequest{Value: "partial-error"})
	if err != nil {
		return fmt.Errorf("partial server-streaming call failed: %w", err)
	}
	first, err := partial.Recv()
	if err != nil {
		return fmt.Errorf("partial server-streaming first receive failed: %w", err)
	}
	if first.GetValue() != "stream:partial:1" {
		return fmt.Errorf("unexpected partial stream reply %q", first.GetValue())
	}
	if _, err = partial.Recv(); status.Code(err) != codes.NotFound {
		return fmt.Errorf("unexpected partial stream completion: %w", err)
	}
	if !reflect.DeepEqual(partial.Trailer().Get("x-error-source"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected partial stream error trailers: %v", partial.Trailer())
	}

	return nil
}

func verifyErrors(client testingpb.TestServiceClient) error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	var trailer metadata.MD
	_, err := client.Unary(
		ctx,
		&testingpb.TestRequest{Value: "error:not-found"},
		grpc.Trailer(&trailer),
	)
	if status.Code(err) != codes.NotFound {
		return fmt.Errorf("unexpected standard error: %w", err)
	}
	if !reflect.DeepEqual(trailer.Get("x-error-source"), []string{"hypervel"}) {
		return fmt.Errorf("unexpected standard error trailers: %v", trailer)
	}

	_, err = client.Unary(ctx, &testingpb.TestRequest{Value: "error:rich"})
	richStatus, ok := status.FromError(err)
	if !ok || richStatus.Code() != codes.InvalidArgument {
		return fmt.Errorf("unexpected rich error: %w", err)
	}
	if richStatus.Message() != "The test value is invalid." {
		return fmt.Errorf("unexpected rich error message %q", richStatus.Message())
	}
	details := richStatus.Details()
	if len(details) != 1 {
		return fmt.Errorf("unexpected rich error details: %v", details)
	}
	errorInfo, ok := details[0].(*errdetails.ErrorInfo)
	if !ok {
		return fmt.Errorf("unexpected rich error detail type %T", details[0])
	}
	if errorInfo.GetReason() != "INVALID_TEST_VALUE" ||
		errorInfo.GetDomain() != "hypervel.dev" ||
		errorInfo.GetMetadata()["value"] != "error:rich" {
		return fmt.Errorf("unexpected rich error detail: %v", errorInfo)
	}

	return nil
}

func verifyDeadline(client testingpb.TestServiceClient) error {
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()

	_, err := client.Unary(ctx, &testingpb.TestRequest{Value: "delay:0.2"})
	if status.Code(err) != codes.DeadlineExceeded {
		return fmt.Errorf("unexpected deadline result: %w", err)
	}

	return nil
}

func verifyHealthService(client healthpb.HealthClient) error {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	response, err := client.Check(ctx, &healthpb.HealthCheckRequest{})
	if err != nil {
		return fmt.Errorf("whole-server health check failed: %w", err)
	}
	if response.GetStatus() != healthpb.HealthCheckResponse_SERVING {
		return fmt.Errorf("unexpected whole-server health status %s", response.GetStatus())
	}

	response, err = client.Check(ctx, &healthpb.HealthCheckRequest{Service: "testing"})
	if err != nil {
		return fmt.Errorf("named health check failed: %w", err)
	}
	if response.GetStatus() != healthpb.HealthCheckResponse_NOT_SERVING {
		return fmt.Errorf("unexpected named health status %s", response.GetStatus())
	}

	list, err := client.List(ctx, &healthpb.HealthListRequest{})
	if err != nil {
		return fmt.Errorf("health list failed: %w", err)
	}
	if list.GetStatuses()[""].GetStatus() != healthpb.HealthCheckResponse_SERVING ||
		list.GetStatuses()["testing"].GetStatus() != healthpb.HealthCheckResponse_NOT_SERVING {
		return fmt.Errorf("unexpected health list: %v", list.GetStatuses())
	}

	_, err = client.Check(ctx, &healthpb.HealthCheckRequest{Service: "missing"})
	if status.Code(err) != codes.NotFound {
		return fmt.Errorf("unexpected missing health service result: %w", err)
	}

	watch, err := client.Watch(ctx, &healthpb.HealthCheckRequest{})
	if err != nil {
		return fmt.Errorf("health watch setup failed: %w", err)
	}
	if _, err = watch.Recv(); status.Code(err) != codes.Unimplemented {
		return fmt.Errorf("unexpected health watch fallback: %w", err)
	}

	return nil
}
