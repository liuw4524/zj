<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

class OpsDatabaseNotification extends DatabaseNotification
{
    protected $table = 'ops_notifications';
}
