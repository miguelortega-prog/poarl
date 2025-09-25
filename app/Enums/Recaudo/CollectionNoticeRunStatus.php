<?php

namespace App\Enums\Recaudo;

enum CollectionNoticeRunStatus: string
{
    case READY = 'ready';
    case IN_PROCESS = 'in_process';
    case FINISHED = 'finished';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
}
