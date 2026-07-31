# Duration-First Attendance Payroll Specification

## Purpose

Define exact-minute payroll recognition.

## Requirements

### Requirement: Temporal boundaries and evidence

The system MUST use half-open intervals `[start,end)`. Company-local bands MUST be `[00:00,06:00)` +75%, `[06:00,18:00)` +25%, and `[18:00,24:00)` +50%. It MUST quantize elapsed time once to complete minutes, partition them without further rounding, and preserve all `Marca observada` timestamps and revisions.

#### Scenario: Exact boundary split

- GIVEN a non-holiday Monday candidate from 17:30 through 18:30
- WHEN attendance is analyzed through its public interface
- THEN 30 minutes are +25% and 30 minutes are +50%
- AND no minute belongs to both bands

#### Scenario: Whole-minute quantization

- GIVEN immutable marks enclosing 480 minutes and 59 seconds
- WHEN attendance is analyzed repeatedly
- THEN exactly 480 minutes are recognized each time
- AND the source timestamps and revisions remain unchanged

### Requirement: Duration-first ordinary quota

On a non-holiday Monday–Saturday `Fecha laboral` with nominal `Jornada asignada` 06:00–14:00, the system MUST classify the first `min(actual minutes, 480)` as ordinary regardless of entry. Only post-quota minutes MAY enter rate bands; pre-14:00 excess in `[06:00,18:00)` MUST be +25%.

#### Scenario: Shifted eight-hour days

- GIVEN observed intervals 06:00–14:00, 08:00–16:00, 09:00–17:00, and 12:00–20:00 on eligible dates
- WHEN each day is analyzed
- THEN each result contains 480 ordinary minutes and zero overtime minutes

#### Scenario: Post-quota worked examples

- GIVEN eligible intervals 06:00–16:00, 09:00–19:00, and 12:00–21:00
- WHEN each day is analyzed
- THEN results are respectively `480 ordinary + 120 at 25%`, `480 ordinary + 60 at 25% + 60 at 50%`, and `480 ordinary + 60 at 50%`

#### Scenario: Pre-14 excess

- GIVEN an eligible interval 00:00–09:00
- WHEN the first 480 minutes satisfy the quota at 08:00
- THEN the remaining 60 minutes are +25%, not ordinary or +75%

### Requirement: Work-date override and overnight classification

The system MUST bind overnight work to its starting `Fecha laboral`. A Sunday or holiday `Fecha laboral` MUST classify all actual minutes +100%, with zero ordinary, shortfall, variation, or other overtime; otherwise post-quota minutes MUST follow wall-clock bands across midnight.

#### Scenario: Sunday and holiday override

- GIVEN equal observed intervals on a Sunday and on a configured holiday
- WHEN each day is analyzed
- THEN all whole minutes are +100% and ordinary minutes are zero

#### Scenario: Overnight working date

- GIVEN a non-holiday Saturday `Fecha laboral` whose post-quota work crosses midnight
- WHEN the interval is analyzed
- THEN its date remains Saturday and post-midnight minutes before 06:00 are +75%
- AND the Sunday override is not applied

### Requirement: Single quota shortfall fact

When actual minutes are below 480 on an eligible `Fecha laboral`, the system MUST expose exactly one fingerprinted `daily_shortfall` of `480 - actual minutes`. It MUST be a non-interval quota fact with no start or end timestamp.

#### Scenario: Seven-hour day

- GIVEN an observed interval 07:00–14:00 totaling seven hours
- WHEN the day is reviewed
- THEN it shows 420 ordinary minutes and one 60-minute `daily_shortfall`
- AND no late-arrival or early-departure deficit interval is created

### Requirement: Transfer-tail exclusion

The system MUST consider exclusion only after the ordinary quota and at least one completed 60-minute overtime boundary. A residual of 1–30 minutes MUST be excluded as transfer time; a residual of 0 or more than 30 minutes MUST remain fully recognized. It MUST preserve the observed exit and excluded-minute count and MUST NOT otherwise round overtime.

#### Scenario: Residual at or below threshold

- GIVEN an eligible observed interval 06:00–16:25
- WHEN attendance is analyzed
- THEN 480 ordinary and 120 detected +25% minutes are exposed
- AND the 16:25 exit and 25 excluded transfer minutes are preserved

#### Scenario: Residual above threshold

- GIVEN an eligible observed interval 06:00–16:31
- WHEN attendance is analyzed
- THEN all 151 post-quota minutes are detected at +25%
- AND excluded transfer minutes are zero
