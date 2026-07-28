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
        ?array $snapshot = null,
    ): Collection {
        return ($snapshot ?? $this->snapshots->forPeriod($payPeriod))['reviews']
            ->filter(fn (PayrollShiftReview $review): bool => $review->analysis->overtimeCandidates->isNotEmpty()
                || $review->analysis->deficits->isNotEmpty()
            )
            ->when(
                $uploadedFileId !== null,
                fn (Collection $reviews): Collection => $reviews->filter(
                    fn (PayrollShiftReview $review): bool => $review->occurrence->marks->contains(
                        fn ($mark): bool => $mark->uploaded_file_id === $uploadedFileId,
                    ),
                ),
            )
            ->values();
    }
}
