<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\Client as ClientModel;
use Hypervel\Passport\ClientRepository as ClientModelRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use SensitiveParameter;

class ClientRepository implements ClientRepositoryInterface
{
    /**
     * Create a new repository instance.
     */
    public function __construct(
        protected ClientModelRepository $clients,
    ) {
    }

    /**
     * Retrieve an active client entity by its identifier.
     *
     * {@inheritdoc}
     */
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $record = $this->clients->findActive($clientIdentifier);

        return $record ? $this->fromClientModel($record) : null;
    }

    /**
     * Validate the client secret.
     *
     * {@inheritdoc}
     */
    public function validateClient(string $clientIdentifier, #[SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        $record = $this->clients->findActive($clientIdentifier);

        return $record && ! empty($clientSecret) && $record->secretMatches($clientSecret);
    }

    /**
     * Get the personal access client for the given provider.
     */
    public function getPersonalAccessClientEntity(string $provider): ?ClientEntityInterface
    {
        return $this->fromClientModel(
            $this->clients->personalAccessClient($provider)
        );
    }

    /**
     * Create a new client entity from the given client model instance.
     */
    protected function fromClientModel(ClientModel $model): ClientEntityInterface
    {
        $client = new Client(
            $model->getKey(),
            $model->name,
            $model->redirect_uris,
            $model->confidential(),
            $model->provider,
            $model->grant_types
        );

        // League's refresh-token policy hook receives no client, so keep it on the issuance.
        TokenIssuance::current()?->setClient($client);

        return $client;
    }
}
