<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

enum StatusCode: int
{
    /**
     * The operation completed successfully.
     */
    case Ok = 0;

    /**
     * The operation was cancelled (typically by the caller).
     */
    case Cancelled = 1;

    /**
     * Unknown error.  An example of where this error may be returned is
     * if a Status value received from another address space belongs to
     * an error-space that is not known in this address space.  Also
     * errors raised by APIs that do not return enough error information
     * may be converted to this error.
     */
    case Unknown = 2;

    /**
     * Client specified an invalid argument.  Note that this differs
     * from FAILED_PRECONDITION.  INVALID_ARGUMENT indicates arguments
     * that are problematic regardless of the state of the system
     * (e.g., a malformed file name).
     */
    case InvalidArgument = 3;

    /**
     * Deadline expired before operation could complete.  For operations
     * that change the state of the system, this error may be returned
     * even if the operation has completed successfully.  For example, a
     * successful response from a server could have been delayed long
     * enough for the deadline to expire.
     */
    case DeadlineExceeded = 4;

    /**
     * Some requested entity (e.g., file or directory) was not found.
     */
    case NotFound = 5;

    /**
     * Some entity that we attempted to create (e.g., file or directory) already exists.
     */
    case AlreadyExists = 6;

    /**
     * The caller does not have permission to execute the specified
     * operation.  PERMISSION_DENIED must not be used for rejections
     * caused by exhausting some resource (use RESOURCE_EXHAUSTED
     * instead for those errors).  PERMISSION_DENIED must not be
     * used if the caller cannot be identified (use UNAUTHENTICATED
     * instead for those errors).
     */
    case PermissionDenied = 7;

    /**
     * Some resource has been exhausted, perhaps a per-user quota, or
     * perhaps the entire file system is out of space.
     */
    case ResourceExhausted = 8;

    /**
     * Operation was rejected because the system is not in a state
     * required for the operation's execution.  For example, directory
     * to be deleted may be non-empty, an rmdir operation is applied to
     * a non-directory, etc.
     *
     * A useful test for choosing among `FailedPrecondition`, `Aborted`, and
     * `Unavailable` is:
     * (a) Use `Unavailable` if the client can retry just the failing call.
     * (b) Use `Aborted` if the client should retry at a higher level
     * (e.g., restarting a read-modify-write sequence).
     * (c) Use `FailedPrecondition` if the client should not retry until
     * the system state has been explicitly fixed. E.g., if an "rmdir"
     * fails because the directory is non-empty, `FailedPrecondition`
     * should be returned since the client should not retry unless
     * they have first fixed up the directory by deleting files from it.
     */
    case FailedPrecondition = 9;

    /**
     * The operation was aborted, typically due to a concurrency issue
     * like sequencer check failures, transaction aborts, etc.
     *
     * See the test above for choosing among `FailedPrecondition`, `Aborted`,
     * and `Unavailable`.
     */
    case Aborted = 10;

    /**
     * Operation was attempted past the valid range.  E.g., seeking or
     * reading past end of file.
     *
     * Unlike `InvalidArgument`, this error indicates a problem that may
     * be fixed if the system state changes. For example, a 32-bit file
     * system will generate `InvalidArgument` if asked to read at an
     * offset that is not in the range [0,2^32-1], but it will generate
     * `OutOfRange` if asked to read from an offset past the current
     * file size.
     *
     * There is a fair bit of overlap between `FailedPrecondition` and
     * `OutOfRange`. Use `OutOfRange` (the more specific error) when it applies
     * so that callers who are iterating through
     * a space can easily detect when they are done.
     */
    case OutOfRange = 11;

    /**
     * Operation is not implemented or not supported/enabled in this service.
     */
    case Unimplemented = 12;

    /**
     * Internal errors.  Means some invariants expected by underlying
     * system has been broken.  If you see one of these errors,
     * something is very broken.
     */
    case Internal = 13;

    /**
     * The service is currently unavailable.  This is a most likely a
     * transient condition and may be corrected by retrying with
     * a backoff. Note that it is not always safe to retry
     * non-idempotent operations.
     *
     * See the test above for choosing among `FailedPrecondition`, `Aborted`,
     * and `Unavailable`.
     */
    case Unavailable = 14;

    /**
     * Unrecoverable data loss or corruption.
     */
    case DataLoss = 15;

    /**
     * The request does not have valid authentication credentials for the
     * operation.
     */
    case Unauthenticated = 16;
}
