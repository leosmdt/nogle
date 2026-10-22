<?php

declare(strict_types=1);

namespace Nogle\Clients;

/** Operação que viola uma regra de unicidade, como CPF repetido; vira resposta 409. */
final class Conflict extends \RuntimeException
{
}
