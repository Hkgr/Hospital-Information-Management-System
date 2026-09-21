<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Periods\PeriodWriter;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function __construct(private PeriodWriter $writer) {}

    public function index(Request $r)
    {
        return response()->json(['data' => $this->writer->listing($this->scope($r))]);
    }

    public function store(Request $r)
    {
        $f = $this->scope($r, 'close');
        $data = $r->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);

        return response()->json(['data' => $this->writer->create($r, $f, $data)], 201);
    }

    public function submit(Request $r, int $period)
    {
        $f = $this->scope($r, 'close');
        $data = $r->validate(['lock_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->writer->submit($r, $f, $period, $data)]);
    }

    public function lock(Request $r, int $period)
    {
        $f = $this->scope($r, 'close');
        $data = $r->validate(['lock_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->writer->lock($r, $f, $period, $data)]);
    }

    public function reopen(Request $r, int $period)
    {
        $f = $this->scope($r, 'reopen');
        $data = $r->validate(['lock_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string']]);

        return response()->json(['data' => $this->writer->reopen($r, $f, $period, $data)]);
    }

    public function unassigned(Request $r)
    {
        return response()->json(['data' => $this->writer->unassigned($this->scope($r))]);
    }

    private function scope(Request $r, string $action = 'view'): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1']]);

        return $this->writer->facility($r->user(), $r->integer('facility_id'), $action);
    }
}
