# Standalone swim goals

The standalone Goals screen reads and writes the installed WordPress plugin's
schema 1.6 goals table. WordPress remains the only identity, database, and
recorded-workout authority. The standalone app adds no goals table, migration, or
copied workout history. Every list, progress query, calendar marker, save, and
delete is scoped to the signed-in owner's ID and requires that owner's existing
workout-management capability, including for users who can manage all swimmers
elsewhere in WordPress.

## One-time performance goals

A swimmer may choose a supported evaluated distance, a target pace per 100 in
meters or yards, or both, and a due date. The date is inclusive. A distance-only
goal completes when a matching evaluated performance is recorded in the selected
course by that date, at any pace. A pace-only goal completes when any evaluated
performance in that course meets or beats the pace target. When both are set,
the performance must match the exact distance and course and meet the pace
threshold. Any stroke can qualify. Only existing evaluated performances count;
manual time or distance fields do not become an evaluated performance.

## Date-range distance goals

The first and last dates are inclusive. Progress sums the recorded native
distance for pool and open-water workouts whose stored unit exactly matches the
goal's `m` or `yd` course. All strokes count. Land workouts, missing distances,
and unknown or different distance units do not count. Meter and yard totals are
never converted or added together. The plugin stores goal targets in its
canonical goal field; the standalone displays the selected course value and
calculates progress from matching native-unit workout fields only.

Progress reflects current recorded history, so correcting, importing, or
removing a workout can change a goal's progress. It does not alter workout
history, personal bests, planned workouts, public visibility, or other training
evaluations. Goals stay private and are not shown by public workout views.

## Calendar

The private calendar marks a one-time goal on its due date. A date-range goal is
marked on its first and last days; a one-day range has a single marker. Expanding
a marker shows target, current progress, and status. Goal markers use the same
owner and capability checks as the Goals screen.
