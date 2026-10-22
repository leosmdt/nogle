<?php

declare(strict_types=1);

namespace Nogle\Ai;

/** Modelo de IA local fora do ar ou com resposta inválida; vira resposta 503. */
final class ModelUnavailable extends \RuntimeException
{
}
