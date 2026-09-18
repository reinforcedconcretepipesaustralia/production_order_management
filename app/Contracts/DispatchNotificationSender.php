<?php

namespace App\Contracts;

use App\Services\Dispatch\DispatchNotification;
use App\Services\Dispatch\DispatchNotificationResult;

interface DispatchNotificationSender
{
    public function send(DispatchNotification $notification): DispatchNotificationResult;
}
