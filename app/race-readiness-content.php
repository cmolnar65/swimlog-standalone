<?php use SwimLogEvaluation\Workout;
/* Presentation adapted from the plugin; all evidence comes from Race_Readiness. */
		$course = (string) ( $event->course_unit ?? '' );
		$stroke = $strokes[ $event->stroke ?? '' ] ?? (string) ( $event->stroke ?? '' );
		$days = $ready['days_until_event'];
		$time_to_event = null === $days
			? '—'
			: ( 0 === (int) $days ? __( 'Today', 'chriss-swim-training-progress-evaluation' ) : sprintf(
				/* translators: %d: number of days until event. */
				_n( '%d day', '%d days', (int) $days, 'chriss-swim-training-progress-evaluation' ),
				(int) $days
			) );

		$pb = null !== $ready['lifetime_pb_duration_ms']
			? Workout::format_duration( $ready['lifetime_pb_duration_ms'] )
			: '—';
		$recent_best = null !== $ready['recent_best_duration_ms']
			? Workout::format_duration( $ready['recent_best_duration_ms'] )
			: '—';
		$recent_pace = null !== $ready['recent_average_pace_100_ms']
			? Workout::format_duration( $ready['recent_average_pace_100_ms'] ) . ' / 100 ' . $course
			: '—';
		?>
		<div class="swimlog-race-readiness">
			<p><strong><?php esc_html_e( 'Time to event:', 'chriss-swim-training-progress-evaluation' ); ?></strong> <?php echo esc_html( $time_to_event ); ?></p>
			<p><strong><?php esc_html_e( 'Current PB:', 'chriss-swim-training-progress-evaluation' ); ?></strong> <?php echo esc_html( $pb ); ?>
				<?php if ( ! empty( $ready['lifetime_pb_achieved_at'] ) ) : ?><small>(<?php echo esc_html( wp_date( 'M j, Y', ( new \DateTimeImmutable( $ready['lifetime_pb_achieved_at'], wp_timezone() ) )->getTimestamp(), wp_timezone() ) ); ?>)</small><?php endif; ?>
			</p>
			<p><strong><?php esc_html_e( 'Recent best:', 'chriss-swim-training-progress-evaluation' ); ?></strong> <?php echo esc_html( $recent_best ); ?>
				<?php if ( ! empty( $ready['recent_best_achieved_at'] ) ) : ?><small>(<?php echo esc_html( wp_date( 'M j, Y', ( new \DateTimeImmutable( $ready['recent_best_achieved_at'], wp_timezone() ) )->getTimestamp(), wp_timezone() ) ); ?>)</small><?php endif; ?>
			</p>
			<p><strong><?php esc_html_e( 'Recent pace:', 'chriss-swim-training-progress-evaluation' ); ?></strong> <?php echo esc_html( $recent_pace ); ?>
				<small><?php echo esc_html( sprintf(
					/* translators: %d: exact-distance recent performance count. */
					_n( '(%d exact-distance performance)', '(%d exact-distance performances)', (int) $ready['recent_performance_count'], 'chriss-swim-training-progress-evaluation' ),
					(int) $ready['recent_performance_count']
				) ); ?></small>
			</p>
			<p><strong><?php esc_html_e( 'Matching training:', 'chriss-swim-training-progress-evaluation' ); ?></strong>
				<?php echo esc_html( \SwimlogStandalone\AnalyticsReports::distance( (float) $ready['distance_native'], $course ) ); ?>;
				<?php echo esc_html( sprintf(
					/* translators: 1: workout count, 2: active length count. */
					__( '%1$d workouts / %2$d active lengths', 'chriss-swim-training-progress-evaluation' ),
					(int) $ready['workout_count'],
					(int) $ready['length_count']
				) ); ?>
			</p>
			<p><strong><?php esc_html_e( 'Recent distance evidence:', 'chriss-swim-training-progress-evaluation' ); ?></strong>
				<?php if ( ! empty( $ready['longest_recent_distance'] ) ) : ?>
					<?php echo esc_html( sprintf(
						/* translators: 1: stroke, 2: distance, 3: course, 4: duration. */
						__( '%1$s %2$d %3$s in %4$s', 'chriss-swim-training-progress-evaluation' ),
						$stroke,
						(int) $ready['longest_recent_distance'],
						$course,
						Workout::format_duration( $ready['longest_recent_duration_ms'] )
					) ); ?>
					<?php if ( $ready['event_distance_or_longer'] ) : ?><small><?php esc_html_e( '(event distance or longer recorded)', 'chriss-swim-training-progress-evaluation' ); ?></small><?php endif; ?>
				<?php else : ?>—<?php endif; ?>
			</p>
			<?php if ( (int) $ready['recent_performance_count'] >= 2 && null !== $ready['recent_change_ms'] ) : ?>
				<p><strong><?php esc_html_e( 'Recent change:', 'chriss-swim-training-progress-evaluation' ); ?></strong>
				<?php
				$change_ms = abs( (int) $ready['recent_change_ms'] );
				if ( 0 === (int) $ready['recent_change_ms'] ) {
					esc_html_e( 'No change from the first exact-distance performance in this lookback period.', 'chriss-swim-training-progress-evaluation' );
				} elseif ( (int) $ready['recent_change_ms'] > 0 ) {
					echo esc_html( sprintf(
						/* translators: %s: elapsed-time improvement. */
						__( '%s faster than the first exact-distance performance in this lookback period.', 'chriss-swim-training-progress-evaluation' ),
						Workout::format_duration( $change_ms )
					) );
				} else {
					echo esc_html( sprintf(
						/* translators: %s: elapsed-time difference. */
						__( '%s slower than the first exact-distance performance in this lookback period.', 'chriss-swim-training-progress-evaluation' ),
						Workout::format_duration( $change_ms )
					) );
				}
				?>
				</p>
			<?php endif; ?>
			<?php if ( null !== $ready['recent_best_vs_pb_ms'] ) : ?>
				<p><strong><?php esc_html_e( 'Recent best vs PB:', 'chriss-swim-training-progress-evaluation' ); ?></strong>
				<?php
				$delta = (int) $ready['recent_best_vs_pb_ms'];
				if ( 0 === $delta ) {
					esc_html_e( 'Matches the current PB.', 'chriss-swim-training-progress-evaluation' );
				} elseif ( $delta > 0 ) {
					echo esc_html( sprintf(
						/* translators: %s: elapsed-time difference. */
						__( '%s slower than the current PB.', 'chriss-swim-training-progress-evaluation' ),
						Workout::format_duration( $delta )
					) );
				} else {
					echo esc_html( sprintf(
						/* translators: %s: elapsed-time difference. */
						__( '%s faster than the stored PB; PB flags may need recalculation.', 'chriss-swim-training-progress-evaluation' ),
						Workout::format_duration( abs( $delta ) )
					) );
				}
				?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Descriptive evidence only. This does not predict race time, fitness, recovery, or whether the swimmer is ready to race.', 'chriss-swim-training-progress-evaluation' ); ?></p>
		</div>
