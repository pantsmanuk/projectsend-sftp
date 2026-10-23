<?php

declare(strict_types=1);

namespace ProjectSendSftp;

use RuntimeException;

// Only fixed, credential-safe messages cross the transport boundary.
final class SftpTransferException extends RuntimeException {}
