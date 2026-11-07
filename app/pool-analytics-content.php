<?php use SwimlogStandalone\AnalyticsReports as AR;use SwimLogEvaluation\Workout; /* Presentation only; calculations stay plugin-owned. */ ?>
            <details class="swimlog-metric-help"><summary><?php esc_html_e('What do these metrics mean?','chriss-swim-training-progress-evaluation'); ?></summary><p class="description"><?php esc_html_e( 'Average pace is distance-weighted from recorded length timing. Pace consistency is the coefficient of variation of per-length pace; lower values mean more consistent pacing. SWOLF is elapsed seconds plus recorded strokes for the same length and is pool-length dependent, so compare it only within the same pool length and similar recording conventions.', 'chriss-swim-training-progress-evaluation' ); ?></p><dl>
                <dt><?php esc_html_e('Average pace','chriss-swim-training-progress-evaluation'); ?></dt><dd><?php esc_html_e('Recorded swim time per 100 meters or yards of timed active lengths. A lower time is faster. Untimed lengths do not enter the pace calculation.','chriss-swim-training-progress-evaluation'); ?></dd>
                <dt><?php esc_html_e('Pace variation (CV)','chriss-swim-training-progress-evaluation'); ?></dt><dd><?php esc_html_e('How much length pace varies relative to its average, expressed as a percentage. Lower means steadier pacing; at least two timed lengths are needed.','chriss-swim-training-progress-evaluation'); ?></dd>
                <dt><?php esc_html_e('Stroke counts and coverage','chriss-swim-training-progress-evaluation'); ?></dt><dd><?php esc_html_e('Strokes per length uses only lengths with recorded counts. Strokes per 25 rescales each count to 25 meters or yards. Coverage is the percentage of active lengths with counts; missing counts are not zero.','chriss-swim-training-progress-evaluation'); ?></dd>
                <dt><?php esc_html_e('SWOLF','chriss-swim-training-progress-evaluation'); ?></dt><dd><?php esc_html_e('Seconds plus strokes for the same length, averaged over lengths with both values. Compare only the same pool length, stroke and recording convention. This is not a universal fitness score. A dash means insufficient recorded data.','chriss-swim-training-progress-evaluation'); ?></dd>
                </dl></details>

			<?php if ( ! $groups ) : ?>
				<p><?php esc_html_e( 'No qualifying active length data are available for these filters.', 'chriss-swim-training-progress-evaluation' ); ?></p>
			<?php else : ?>
				<h3><?php esc_html_e( 'Summary', 'chriss-swim-training-progress-evaluation' ); ?></h3>
				<div class="detail-table-wrap" tabindex="0" role="region" aria-label="Swimming report data"><table class="analytics-table">
					<thead><tr>
						<th><?php esc_html_e( 'Pool', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Stroke', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Workouts', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Active lengths', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Distance', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: course unit m or yd. */ __('Average pace / 100 %s','chriss-swim-training-progress-evaluation'), $course ) ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo esc_html( sprintf( /* translators: %s: course unit m or yd. */ __('Fastest length pace / 100 %s','chriss-swim-training-progress-evaluation'), $course ) ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Pace variation (CV, %)', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Avg strokes / length', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo esc_html( sprintf( /* translators: %s: course unit m or yd. */ __('Strokes / 25 %s','chriss-swim-training-progress-evaluation'), $course ) ); ?></th>
						<th><?php esc_html_e( 'Avg SWOLF', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Lengths with stroke counts (%)', 'chriss-swim-training-progress-evaluation' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $groups as $group ) : ?>
						<tr>
							<td><?php echo esc_html( AR::distance( $group['pool_length'], $group['course_unit'] ) ); ?></td>
							<td><?php echo esc_html( $strokes[ $group['stroke'] ] ?? $group['stroke'] ); ?></td>
							<td><?php echo esc_html( (int) $group['workout_count'] ); ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo esc_html( (int) $group['active_lengths'] ); ?></td>
							<td><?php echo esc_html( AR::distance( $group['distance_native'], $group['course_unit'] ) ); ?></td>
							<td><?php echo null !== $group['avg_pace_100_ms'] ? esc_html( Workout::format_duration( $group['avg_pace_100_ms'] ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $group['best_pace_100_ms'] ? esc_html( Workout::format_duration( $group['best_pace_100_ms'] ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $group['pace_consistency_cv'] ? esc_html( number_format_i18n( $group['pace_consistency_cv'], 1 ) . '%' ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $group['avg_stroke_count'] ? esc_html( number_format_i18n( $group['avg_stroke_count'], 1 ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $group['normalized_strokes_per_25'] ? esc_html( number_format_i18n( $group['normalized_strokes_per_25'], 1 ) ) : '—'; ?></td>
							<td><?php echo null !== $group['avg_swolf'] ? esc_html( number_format_i18n( $group['avg_swolf'], 1 ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo esc_html( number_format_i18n( $group['stroke_count_coverage_percent'], 0 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>

				<h3><?php esc_html_e( 'Monthly Trends', 'chriss-swim-training-progress-evaluation' ); ?></h3>
				<div class="detail-table-wrap" tabindex="0" role="region" aria-label="Swimming report data"><table class="analytics-table">
					<thead><tr>
						<th><?php esc_html_e( 'Month', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Pool', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Stroke', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php esc_html_e( 'Distance', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: course unit m or yd. */ __('Average pace / 100 %s','chriss-swim-training-progress-evaluation'), $course ) ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Pace variation (CV, %)', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php esc_html_e( 'Avg strokes / length', 'chriss-swim-training-progress-evaluation' ); ?></th>
						<th <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo esc_html( sprintf( /* translators: %s: course unit m or yd. */ __('Strokes / 25 %s','chriss-swim-training-progress-evaluation'), $course ) ); ?></th>
						<th><?php esc_html_e( 'Avg SWOLF', 'chriss-swim-training-progress-evaluation' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $groups as $group ) : foreach ( $group['months'] as $month ) : ?>
						<tr>
							<td><?php echo esc_html( $month['month'] ); ?></td>
							<td><?php echo esc_html( AR::distance( $group['pool_length'], $group['course_unit'] ) ); ?></td>
							<td><?php echo esc_html( $strokes[ $group['stroke'] ] ?? $group['stroke'] ); ?></td>
							<td><?php echo esc_html( AR::distance( $month['distance_native'], $group['course_unit'] ) ); ?></td>
							<td><?php echo null !== $month['avg_pace_100_ms'] ? esc_html( Workout::format_duration( $month['avg_pace_100_ms'] ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $month['pace_consistency_cv'] ? esc_html( number_format_i18n( $month['pace_consistency_cv'], 1 ) . '%' ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $month['avg_stroke_count'] ? esc_html( number_format_i18n( $month['avg_stroke_count'], 1 ) ) : '—'; ?></td>
							<td <?php if ( ! $additional ) echo 'hidden'; ?>><?php echo null !== $month['normalized_strokes_per_25'] ? esc_html( number_format_i18n( $month['normalized_strokes_per_25'], 1 ) ) : '—'; ?></td>
							<td><?php echo null !== $month['avg_swolf'] ? esc_html( number_format_i18n( $month['avg_swolf'], 1 ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
