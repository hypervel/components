<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerMetadata;

class ServerMetadataController
{
    /**
     * Create the public server metadata endpoints.
     */
    public function __construct(
        protected ServerMetadata $metadata,
        protected ServerFactory $servers,
    ) {
    }

    /**
     * Publish metadata only at the current issuer's discovery location.
     */
    public function show(Request $request): JsonResponse
    {
        // The proxy's application prefix belongs on endpoint URLs, not before the well-known path.
        abort_unless($request->getSchemeAndHttpHost() . rtrim($request->getPathInfo(), '/') === $this->metadata->discoveryUrl(), 404);

        return $this->response($this->metadata->toArray());
    }

    /**
     * Publish the already parsed public verification keys.
     */
    public function jwks(): JsonResponse
    {
        return $this->response($this->servers->jwks());
    }

    /**
     * Bound document freshness by the server's key publication and removal policy.
     */
    protected function response(array $data): JsonResponse
    {
        return (new JsonResponse($data))->setCache([
            'public' => true,
            'max_age' => $this->servers->settings()->metadataCacheLifetime,
            'must_revalidate' => true,
        ]);
    }
}
