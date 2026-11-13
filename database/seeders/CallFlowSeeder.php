<?php

namespace Database\Seeders;

use App\Services\Flows\FlowPublisher;
use Illuminate\Database\Seeder;

class CallFlowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    /**
     * Publish version 1 of the IVR flow (built from the committed audio
     * bundle) and open a draft for editing, unless a flow exists already.
     */
    public function run(FlowPublisher $publisher): void
    {
        $publisher->draft();
    }
}
