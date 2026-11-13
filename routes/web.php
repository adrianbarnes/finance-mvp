<?php

use App\Http\Controllers\CallController;
use App\Livewire\CallConsole;
use App\Livewire\CallFlows\Builder as CallFlowBuilder;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Languages\Index as LanguagesIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('customers', CustomersIndex::class)->name('customers.index');
    Route::livewire('customers/{customer}', CustomersShow::class)->name('customers.show');
    Route::livewire('ivr-flow', CallFlowBuilder::class)->name('call-flows.builder');
    Route::livewire('languages', LanguagesIndex::class)->name('languages.index');
    Route::livewire('call-console', CallConsole::class)->name('call-console');

    Route::post('calls', [CallController::class, 'store'])->name('calls.store');
    Route::post('calls/{call}/utterances', [CallController::class, 'utterance'])->name('calls.utterances.store');
    Route::post('calls/{call}/inputs', [CallController::class, 'input'])->name('calls.inputs.store');
    Route::delete('calls/{call}', [CallController::class, 'destroy'])->name('calls.destroy');
    Route::get('call-turns/{turn}/audio', [CallController::class, 'turnAudio'])->name('call-turns.audio');
});

require __DIR__.'/settings.php';
