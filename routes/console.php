<?php

use App\Console\Commands\PruneInactiveChatHistories;
use Illuminate\Support\Facades\Schedule;

Schedule::command(PruneInactiveChatHistories::class)->everyFiveMinutes();