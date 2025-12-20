GigTune vision
GigTune is a two-sided live-performance marketplace that matches Clients with verified Artists using availability, location, and style-fit, then manages the full transaction lifecycle end-to-end: discovery → booking request → confirmation → escrow → performance verification → payout → review.
Not “Uber for musicians”. More like a professional booking operating system for live entertainment.
________________


What makes GigTune unique
1) “Fit-based” matching, not just “nearby”
Bolt/Uber is proximity-first. GigTune should be:
* Fit score: genre/style, instrumentation, event type, budget range, availability, travel radius.

* Performance-ready profiles: 30-second demos + tags + reliability metrics.

* Outcome: clients stop guessing. Artists get relevant gigs, not spam requests.

2) Availability and reliability as first-class signals
A booking platform fails when artists don’t show, respond late, or have unclear availability. GigTune should introduce:
   * Availability calendar + instant confirmation windows (e.g., “respond in 2 hours”).

   * Reliability rating separate from performance rating (punctuality, communication, professionalism).

   * No-show handling tied to policy and enforcement.

3) Escrow + milestones for trust
Escrow is good, but “release when done” is vague. Add structure:
      * Deposit on booking confirmation (optional rule).

      * Completion confirmation + dispute window.

      * Auto-release after X hours if no dispute is raised.

This reduces admin workload and protects both sides.
4) Video demos treated like products, not uploads
The demo video should be a conversion tool:
         * Standardised format: 30 seconds, minimum audio quality, clear camera framing.

         * Auto-generated preview thumbnails.

         * Compression and streaming optimised for mobile and low bandwidth.

5) Real-time map should be “availability mode”
Real-time location can create privacy and safety issues. Make it mature:
            * Visibility toggle plus approximate location (area-level, not precise pin) by default.

            * “Available now” mode to show who’s open to last-minute gigs.

            * Travel radius and “willing to travel” badge.

________________


The refined product blueprint (how it actually works)
A) Two-sided workflow
Client flow
               1. Choose event type (wedding, lounge, club, corporate, birthday, church, etc.)

               2. Pick date/time, location, duration, budget

               3. Choose talent type (DJ, band, guitarist, keys, etc.)

               4. Browse matches with Fit score + demos

               5. Send booking request (with optional notes)

               6. Pay into escrow

               7. Artist accepts/declines within a time window

               8. Event happens

               9. Completion confirmed → payout released → review prompted

Artist flow
                  1. Register + verify basic details

                  2. Build profile: instrument + styles + secondary keys

                  3. Upload demo video(s)

                  4. Set availability + travel radius + visibility mode

                  5. Receive booking requests

                  6. Accept/decline with notes

                  7. Perform

                  8. Payout after completion

                  9. Maintain reputation + reliability

B) Core entities (for clean system design)
                     * Users: Client, Artist, Admin

                     * Artist Profile: instrument types + style tags + media + pricing range

                     * Availability: calendar + “available now” toggle

                     * Booking: status machine (requested → accepted → escrowed → completed → paid → reviewed / disputed)

                     * Payments: subscription, escrow, payouts, commission

                     * Reviews: verified-only, booking-linked

                     * Messages: booking-linked threads

________________


Product modules (reframed from the feature list)
1) Identity, Profiles, and Verification
                        * Role-based registration (Client / Artist)

                        * Artist category + subcategory system (keys → synthesis/Durban style)

                        * Optional verification levels:

                           * Email/phone verified

                           * ID verified (later)

                           * “Pro verified” (later)

2) Media and Showcase Engine
                              * Mandatory 30s demo

                              * Compression pipeline

                              * CDN-style delivery (or at least optimised streaming)

                              * Moderation rules for quality and safety

3) Discovery and Matching
                                 * Map + list view

                                 * Fit score sorting

                                 * Filters: price range, availability, travel radius, rating, style tags

                                 * “Shortlist” feature for clients

4) Booking + Escrow Transaction Engine
                                    * Booking request with expiry timer

                                    * Escrow capture

                                    * Commission automatically deducted at payout

                                    * Dispute window + evidence submission

5) Reputation System
Separate:
                                       * Performance rating (talent)

                                       * Reliability rating (professionalism)
Plus:

                                       * response time

                                       * acceptance rate

                                       * cancellation rate

                                       * no-show flags

6) Messaging + Notifications
                                          * Booking-thread messaging

                                          * Email + push-ready notifications

                                          * System alerts: subscription renewal, booking requests, payout status

7) Admin Console
                                             * User management

                                             * Listing moderation

                                             * Dispute resolution workflow

                                             * Fraud flags (review abuse, off-platform attempts)

                                             * Analytics dashboard

________________


Differentiators you can pitch (simple and powerful)
                                                * “Fit-based matching with verified demos”

                                                * “Escrow-backed bookings to reduce risk”

                                                * “Reliability scoring so clients book with confidence”

                                                * “Availability mode for last-minute gigs”

                                                * “Structured disputes and payouts”

________________


If you want to make it feel premium
Add one signature feature (choose one):
                                                   1. QuickBook: instant confirmation artists for fixed packages (30/60/90 min sets).

                                                   2. GigTune Sets: artists sell predefined set types (wedding set, lounge set, club set).

                                                   3. Event Brief Builder: client answers 6 questions and gets ideal match suggestions automatically.

                                                   4. Agency Mode: venues manage recurring gigs and preferred rosters.
