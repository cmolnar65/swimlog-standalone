# Standalone requirements carried forward

## Portability

- Keep WordPress URL, local WordPress bootstrap path and application URL in server configuration.
- Remove installation-specific paths from the deployment scripts and default companion/config discovery before distribution to other site owners.
- Make the deployment health URL configurable; do not silently infer one from browser input.
- Keep labels and WordPress tool links derived from configured origins.
- Do not introduce a second account system, copied production database or duplicate calculations.

## Interface contract

- Existing plugin banner/icon are the visual source: navy, bright blue, cyan, rounded panels and clear typography.
- The signed-in home page is the calendar; all screens share top navigation.
- Every new screen must support desktop and mobile browser widths, keyboard access, touch targets, long content, empty/error states and owner privacy.
- Desktop calendar uses seven columns; phone calendar uses an agenda, preserving the same dates and data.
- First calendar phase is read-only: sessions (including authorized private land activities), planned swims and events. Recorded-workout detail pop-ups and protected fallback pages are implemented. The standalone Workout Log now provides filtered, paginated recorded history with the same details. Measurements and editing remain subsequent phases.
- Existing WordPress tools remain reachable through the explicitly labelled menu until standalone counterparts are implemented.
- Simulated phone browser checks do not establish physical-device acceptance.
