<?php

namespace App\Services\Retrieval;

use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use Illuminate\Container\Attributes\Bind;

/** Recuperação de evidências documentais; independente de qualquer gerador de texto. */
#[Bind(PostgresKnowledgeRetriever::class)]
interface KnowledgeRetriever
{
    public function retrieve(RetrievalQuery $query): RetrievalResult;
}
