<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPrintJobRequest;
use App\Labels\Printing\PrintJobSubmitter;
use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class PrintJobSubmissionController extends Controller
{
    public function __invoke(SubmitPrintJobRequest $request, PrintJobSubmitter $printJobSubmitter): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $submitter */
        $authenticatedUser = $request->user();
        $operator = isset($validated['user_id'])
            ? User::query()->findOrFail($validated['user_id'])
            : null;

        try {
            $job = $printJobSubmitter->submit(
                LabelTemplate::query()->findOrFail($validated['label_template_id']),
                Printer::query()->findOrFail($validated['printer_id']),
                $authenticatedUser,
                $operator,
                $validated['pin'] ?? null,
                $validated['quantity'],
                $validated['values'],
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages(['print_job' => $exception->getMessage()]);
        }

        return response()->json([
            'job_id' => $job->id,
            'job_identifier' => $job->shortIdentifier(),
            'status' => $job->status->value,
            'quantity' => $job->quantity,
        ], 201);
    }
}
