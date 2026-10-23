<?php

declare(strict_types=1);

namespace ProjectSendSftp;

enum TransferStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}
