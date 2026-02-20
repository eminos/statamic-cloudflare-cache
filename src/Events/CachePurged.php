<?php

namespace Eminos\StatamicCloudflareCache\Events;

class CachePurged
{
    public function __construct(
        public array $urls,
        public bool $purgedEverything
    ) {}
}
