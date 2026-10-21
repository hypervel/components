<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

interface Gateway extends AudioGateway, EmbeddingGateway, ImageGateway, StepTextGateway, TranscriptionGateway
{
}
