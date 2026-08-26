<?php

namespace App\Services\Attendance;

use App\Models\PayPeriod;
use Illuminate\Support\Collection;

class AttendanceReviewQuery
{
    public function __construct(private PayrollPeriodReviewSnapshot $snapshots) {}

    /** @return Collection<int, PayrollShiftReview> */
    public function forPeriod(
        PayPeriod $payPeriod,
        ?int $uploadedFileId = null,
        ?PayrollPeriodReviewSnapshotContext $snapshot = null,
    ): Collection {
        $reviews = collect();
        $snapshot ??= $this->snapshots->captureForPeriod($payPeriod);

        $this->snapshots->forEachReview($snapshot, function (PayrollShiftReview $review) use ($reviews, $uploadedFileId): void {
            if ($review->analysis->overtimeCandidates->isEmpty()
                && $review->analysis->deficits->isEmpty()
                && $review->analysis->variations->isEmpty()) {
                return;
            }
            if ($uploadedFileId !== null && ! $review->occurrence->marks->contains(
                fn ($mark): bool => $mark->uploaded_file_id === $uploadedFileId,
            )) {
                return;
            }
            $reviews->push($review);
        });

        return $reviews->values();
    }
}
