<?php

namespace App\Services\Scheduling\Actions;

interface StageAction
{
    /** Uno de StageActionCatalog. */
    public function type(): string;

    public function execute(StageActionContext $context): StageActionResult;
}
