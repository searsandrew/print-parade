<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPrintJobRequest;
use App\Labels\Printing\PrintJobSubmitter;
use App\Models\Employee;
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
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $operator = isset($validated['employee_id'])
            ? Employee::query()->whereKey($validated['employee_id'])->firstOrFail()
            : null;
        $template = LabelTemplate::query()->whereKey($validated['label_template_id'])->firstOrFail();
        $printer = Printer::query()->whereKey($validated['printer_id'])->firstOrFail();

        try {
            $job = $printJobSubmitter->submit(
                $template,
                $printer,
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
