# RAHA — Manual UAT checklist

Maintained with the code. **No result in this file has been filled in**: the *Actual result*, *Pass/Fail* and *Evidence* columns are for the testers.
Automated coverage (backend `php artisan test`, frontend vitest, `Raha-Front/e2e` Playwright) is a safety net, not a replacement for this checklist.

Environment, accounts per role, suggested execution order, financial reconciliation index, evidence procedure and known limitations: see [UAT_HANDOVER.md](UAT_HANDOVER.md).

## How to use
1. Run against the isolated UAT/dev database only (never production). Use synthetic data only.
2. Sign in with the account named in the *Role/account* column. In the dev environment the seeded accounts use the shared dev password; **those accounts must not exist in production**.
3. Follow *Steps*, compare with *Expected result*, then fill *Actual result*, set *Pass/Fail* (Pending → Pass / Fail) and attach a screenshot name or link under *Evidence*.
4. Record any defect with its Test ID in *Notes*. A test is only Pass when every part of its expected result is true.
5. Repeat the key flows on a phone-width screen (390 px) and in English (language switch) where marked **[M]** / **[EN]**.

## Roles used
Beneficiary (portal), Auditor (المدقق), Medical committee (اللجنة الطبية), Social researcher (الباحث الاجتماعي), Care manager (مدير الرعاية الصحية),
Finance, Executive (المدير التنفيذي), Team lead (فريق 01), Patient relations (علاقات المرضى), Admin (مدير النظام), Provider (مقدم الخدمة, 3 demo providers).

Column legend: **Actual result** blank · **Pass/Fail** = Pending · **Evidence** = screenshot file name / link.

## 1. Beneficiary portal

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| BEN-01 | Visitor | Public home / | Open the site without signing in. Browse services. Switch language. | Public service list loads; no private data; language switches Arabic/English and layout flips. |  | Pending |  |  |
| BEN-02 | Visitor | Registration /login?type=beneficiary | Choose register. Enter first/family name, national id, password. Submit. | Account created, or for an existing beneficiary record an OTP/ownership step is requested; weak password refused with a clear message. |  | Pending |  |  |
| BEN-03 | Visitor | Registration OTP | Enter a wrong code, then the correct code (dev shows it only in dev mode). | Wrong code refused; correct code completes registration; code cannot be reused. |  | Pending |  |  |
| BEN-04 | Beneficiary | Login | Sign in with national id and password. Then sign out. | Lands on /beneficiary; after logout the browser returns to login and Back does not reveal data. |  | Pending |  |  |
| BEN-05 | Beneficiary | Login — lock | Enter a wrong password repeatedly. | Account locks temporarily with the documented message; correct password is refused while locked. |  | Pending |  |  |
| BEN-06 | Beneficiary | Forgot password | Request a reset by national id; complete with the code. | Neutral message (does not reveal whether the account exists); new password works; old one does not. |  | Pending |  |  |
| BEN-07 | Beneficiary | Profile | Open profile, edit city/district/mobile, save, reload. | Values persist after reload; invalid mobile refused. |  | Pending |  |  |
| BEN-08 | Beneficiary | New request — recipient step | Start a request. Try Next without choosing; then choose 'for myself'. | Cannot advance without a choice; identity fields are locked to the account owner. |  | Pending |  |  |
| BEN-09 | Beneficiary | New request — family member | Choose 'another family member', pick the relationship, enter the patient's data. | Patient data (not the account owner's) is on the request; relationship saved; owner can follow it. |  | Pending |  |  |
| BEN-10 | Beneficiary | New request — validation | Leave required fields empty on each step; enter an invalid id number for the chosen id type. | Each step lists the missing/invalid fields; wizard stays on the step. |  | Pending |  |  |
| BEN-11 | Beneficiary | New request — service & medical | Pick a service, fill medical/condition data. | Service-specific questions appear; data kept when going Back and Next. |  | Pending |  |  |
| BEN-12 | Beneficiary | New request — documents | Attach the medical report (PDF) with a report date within the allowed window; try a too-old date and a wrong file type. | Valid file accepted; old report date / wrong type / oversize refused with a message. |  | Pending |  |  |
| BEN-13 | Beneficiary | New request — rental contract | Choose rented housing and enter rent. | Rental contract upload becomes required; request cannot be submitted without it; with it, the file is listed. |  | Pending |  |  |
| BEN-14 | Beneficiary | New request — review & submit | Open review. Try to submit without the declaration, then tick it and submit. | Submit disabled until the declaration is ticked; confirmation dialog; request number REQ-xxxxxx shown. |  | Pending |  |  |
| BEN-15 | Beneficiary | My requests | Open the requests list and the new request. | New request listed with status 'new'; detail shows only beneficiary-safe information (no internal notes, no finance). |  | Pending |  |  |
| BEN-16 | Beneficiary | Draft handling | Fill part of the wizard, reload the page, return. | Draft restored on the same device/session; cleared after submit and after logout. |  | Pending |  |  |
| BEN-17 | Beneficiary | Returned for correction | Have staff return the request (see STF-xx); open the request. | Correction instructions and the documents to replace are shown; resubmitting keeps the same request number. |  | Pending |  |  |
| BEN-18 | Beneficiary | Status lookup | Use the status lookup with the request number. | Shows a minimal status; another person's number reveals nothing. |  | Pending |  |  |
| BEN-19 | Beneficiary | Notifications | Open notifications; mark one read; reload. | Read state persists; only own notifications. |  | Pending |  |  |
| BEN-20 | Beneficiary | Service confirmation & evaluation | After the visit, confirm receipt and rate the service. | Confirmation recorded; request moves to the closing stages. |  | Pending |  |  |
| BEN-21 | Beneficiary | Isolation | Type another beneficiary's request URL. | Not found / denied; no data shown. |  | Pending |  |  |
| BEN-22 | Beneficiary | Mobile [M] | Repeat registration, wizard and requests list at 390 px. | No horizontal scroll; every control reachable and readable. |  | Pending |  |  |
| BEN-23 | Beneficiary | English [EN] | Switch to English and walk the wizard. | All labels, errors and messages are English; numbers/dates readable. |  | Pending |  |  |

## 2. Staff requests, medical and social review

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| STF-01 | Auditor | Dashboard & work queue | Sign in; open the dashboard and work queue. | Stage counts and 'my tasks' shown; a newly submitted request is in the queue. |  | Pending |  |  |
| STF-02 | Auditor | Claim task | Open the request; try to approve without claiming; then claim. | Approve refused until claimed; after claim only the owner can decide. |  | Pending |  |  |
| STF-03 | Auditor | Audit approve | Confirm review and approve. | Request moves to medical review; confirmation required; timeline shows the action. |  | Pending |  |  |
| STF-04 | Auditor | Return for correction | Return the request choosing fields/documents and a reason. | Beneficiary sees the instructions; status 'returned'; cannot return without fields/documents. |  | Pending |  |  |
| STF-05 | Auditor | Reject | Reject with a reason (on a test request). | Reason mandatory; beneficiary sees a final status; archived rules apply. |  | Pending |  |  |
| STF-06 | Medical committee | Medical review — required fields | Open the medical review dialog; submit with the opinion or recommendation empty. | Refused naming the missing field. |  | Pending |  |  |
| STF-07 | Medical committee | Medical review — Provider/Price optional | Complete only the medical opinion and recommendation; leave Provider and Price empty; submit. | Accepted; request moves to social review; the review shows no provider/price. |  | Pending |  |  |
| STF-08 | Medical committee | Medical review — with Provider/Price | Repeat on another request choosing a suggested provider and an advisory price. | Accepted; values are saved and displayed in the review history; Finance is still responsible for the final provider. |  | Pending |  |  |
| STF-09 | Medical committee | Medical review — invalid price | Enter 0 or a negative price. | Refused with a message; request unchanged. |  | Pending |  |  |
| STF-10 | Medical committee | Needs quotation | Choose the decision 'needs provider pricing' with a justification. | Quotation flow becomes required (see QUO-xx). |  | Pending |  |  |
| STF-11 | Social researcher | Social review | Complete summary, assessment, recommendation, justification; try partial contribution with 101%. | Percentage must be 0–100; request moves to manager approval. |  | Pending |  |  |
| STF-12 | Care manager | Preliminary approval | Review the summary of decisions and approve. | Request becomes 'preliminary approved'; for finance requests the finance step starts. |  | Pending |  |  |
| STF-13 | Care manager | Send back | Send the request back to the medical or social review with a reason. | Returns to the chosen internal stage; the beneficiary sees nothing. |  | Pending |  |  |
| STF-14 | Care manager | Staff-created request | Create a request on behalf of a beneficiary (staff new-request page). | Request created for the chosen beneficiary and appears in the queue. |  | Pending |  |  |
| STF-15 | Team lead | Permissions | Open each menu item. | No finance/users/audit/reports pages; direct URLs show nothing. |  | Pending |  |  |
| STF-16 | Any staff | Case tabs | Open overview, medical, social, documents, workflow, notes tabs. | Each tab shows only what the role may see. |  | Pending |  |  |
| STF-17 | Any staff | Documents | Preview/download a document; mark a document as needing replacement. | File opens; review flag saved. |  | Pending |  |  |
| STF-18 | Any staff | Notes | Add an internal note. | Visible to staff; never to the beneficiary or provider. |  | Pending |  |  |

## 3. Fast Track

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| FT-01 | Admin | Settings — Fast Track policy | Open the Fast Track settings, review the rules, change a threshold, save, reload. | Saved value persists; change is audited. |  | Pending |  |  |
| FT-02 | Beneficiary | Eligibility | Submit a request that meets the Fast Track rules. | Request is marked Fast Track and skips the stages the policy allows. |  | Pending |  |  |
| FT-03 | Beneficiary | Not eligible | Submit a request that does not meet the rules. | Normal workflow; no Fast Track marker. |  | Pending |  |  |
| FT-04 | Auditor | Fast Track queue | Open a Fast Track request. | Marker visible; required checks still enforced. |  | Pending |  |  |

## 4. Provider invitation and assessment

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| PRV-01 | Care manager | Provider invitation | From a case needing pricing, select eligible providers and a deadline. | Only eligible providers listed; invitation created for each. |  | Pending |  |  |
| PRV-02 | Provider | Invitations list | Sign in as provider; open invitations. | Only own invitations; no beneficiary national id or internal notes. |  | Pending |  |  |
| PRV-03 | Provider | Respond to invitation | Accept or decline an invitation. | Status recorded; staff see the response. |  | Pending |  |  |
| PRV-04 | Provider | Contact beneficiary | Open the execution contact after accepting. | Only the minimal contact data needed is shown. |  | Pending |  |  |
| PRV-05 | Provider | Assessment | Fill and submit the provider assessment (needs, scope, visits). | Saved; staff see it; required fields enforced. |  | Pending |  |  |
| PRV-06 | Provider | Provider invitation link | Open the invitation page for a provider without an account/with a stale link. | Safe message; nothing from other providers. |  | Pending |  |  |
| PRV-07 | Provider | Isolation | Try another provider's invitation/case URL. | Not found / denied. |  | Pending |  |  |

## 5. Quotations

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| QUO-01 | Care manager | Create quotation round | Open the quotation round page; check invited providers and deadline. | Round shows state and the invited providers. |  | Pending |  |  |
| QUO-02 | Provider | Submit quotation (draft) | Open the quotation form; save a draft; reload. | Draft persists. |  | Pending |  |  |
| QUO-03 | Provider | Submit quotation | Enter itemized lines, expected start, notes; submit. | Total computed correctly; empty quotation refused by the server; a submitted quotation cannot be edited. |  | Pending |  |  |
| QUO-04 | Provider | Competing providers | As a second provider, open the same round. | Cannot see the other provider's prices. |  | Pending |  |  |
| QUO-05 | Care manager | Comparison | Compare submitted quotations and select one. | Comparison table is correct; selection recorded; request advances. |  | Pending |  |  |
| QUO-06 | Care manager | Cancel round | Cancel a round with a reason. | Round closed; providers notified; request returns to the right stage. |  | Pending |  |  |
| QUO-07 | Care manager | Quotation PDF | Download the quotation PDF. | Arabic text correct and RTL; numbers and totals match the screen. |  | Pending |  |  |

## 6. Finance and wallets

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| FIN-01 | Finance | Wallets list | Open the wallets page; check ledger/reserved/committed/available per wallet. | available = ledger − reserved − committed for every wallet. |  | Pending |  |  |
| FIN-02 | Finance | Wallet funding | Add test funding to a wallet with a note. | Ledger shows a FUNDING_ADDED entry; balances update; audited. |  | Pending |  |  |
| FIN-03 | Finance | Wallet adjustment | Apply a negative authorized adjustment with a reason. | Ledger and balances reflect it; reason required. |  | Pending |  |  |
| FIN-04 | Auditor | Wallet permissions | Open the wallets page without wallet-management rights. | No funding/adjust actions. |  | Pending |  |  |
| FIN-05 | Finance | Allocations | For a preliminarily approved request, split the amount across wallets. | Allocation totals must equal the approved amount; ineligible wallets refused. |  | Pending |  |  |
| FIN-06 | Finance | Reservation | Approve the split and reserve funds. | Reserved increases, available decreases by the same amount. |  | Pending |  |  |
| FIN-07 | Finance | Insufficient balance | Try to reserve more than available. | Refused with a clear message. |  | Pending |  |  |
| FIN-08 | Finance | Reservation release | Release a reservation (test request). | Reserved returns to available; ledger shows RESERVATION_RELEASE. |  | Pending |  |  |
| FIN-09 | Finance | Records & history | Open the finance records and the request's finance history. | Every step listed with who/when. |  | Pending |  |  |
| FIN-10 | Finance | Payment confirmation & settlement | After the service, confirm payment on a request whose invoice covers the commitment. | Commitments become 'مسدد' (settled); one negative DEDUCTION per wallet; ledger and committed fall by the amount, available unchanged; request archived. |  | Pending |  |  |
| FIN-11 | Finance | Payment reconciliation — invoice lower than approved cost | Have the provider upload an invoice lower than the approved total cost, then try to confirm payment. | Confirmation is REFUSED with a message requiring finance review; request stays in finance review (not archived); no deduction; reservations stay committed; balances unchanged. |  | Pending |  |  |
| FIN-12 | Finance | Payment reconciliation — invoice higher than approved cost | Use an invoice higher than the approved total cost; confirm payment. | Refused with the same finance-review message; nothing changes. |  | Pending |  |  |
| FIN-13 | Finance | Payment reconciliation — invoice equals commitments only | On a request with an approved beneficiary contribution, use an invoice equal to the RAHA commitments only. | Refused: the invoice must equal the approved total (RAHA wallets + approved beneficiary contribution). |  | Pending |  |  |
| FIN-14 | Finance | Payment reconciliation — no invoice | Try to confirm payment before any invoice exists on a request with commitments. | Refused: no invoice to reconcile; request not archived. |  | Pending |  |  |
| FIN-15 | Finance | Payment reconciliation — multi-wallet match | On a request funded by two wallets, confirm payment with an invoice equal to the approved total. | Archived; each wallet gets exactly one negative deduction equal to its commitment; available unchanged. |  | Pending |  |  |
| FIN-16 | Finance | Payment reconciliation — retry after correction | After a refusal, have the invoice corrected to the approved total (policy owner decides how) and confirm again. | Settles once; no duplicate deduction. |  | Pending |  |  |
| FIN-17 | Finance | Repeat confirmation | Try to confirm payment again on the archived request. | Refused; no second deduction. |  | Pending |  |  |
| FIN-18 | Finance | Archived legacy request | Open a request archived before settlement was introduced. | Unchanged; its commitment is not auto-settled. |  | Pending |  |  |
| FIN-19 | Finance | Reports — wallet balances | Open the wallet balances report and export it. | Report equals the wallets page. |  | Pending |  |  |

## 7. Executive approvals

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| EXE-01 | Executive | Approvals queue | Open requests awaiting executive approval. | Only requests at that stage are listed. |  | Pending |  |  |
| EXE-02 | Executive | Approve | Approve a reserved request. | Reservation converts to a commitment; request continues to authorization. |  | Pending |  |  |
| EXE-03 | Executive | Reject / return | Reject with a reason. | Reason mandatory; reservation released; the finance team is notified. |  | Pending |  |  |
| EXE-04 | Executive | Executive monitoring | Open executive monitoring and analytics. | Numbers match the lists/reports; no edit actions. |  | Pending |  |  |

## 8. Authorizations and assignment

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| AUT-01 | Finance | Issue authorization | Issue the authorization letter for an approved request. | Letter created with number, scope, amount, validity. |  | Pending |  |  |
| AUT-02 | Finance | Authorization PDF | Download the authorization PDF. | Official layout; Arabic correct; data equals the screen; logo/stamp only if configured. |  | Pending |  |  |
| AUT-03 | Care manager | Assign provider | Assign the authorized request to a provider. | Assignment created; provider notified. |  | Pending |  |  |
| AUT-04 | Care manager | Cancel / replace letter | Cancel an issued letter with a reason. | Letter cancelled; commitment handled per the finance rules; audited. |  | Pending |  |  |
| AUT-05 | Provider | Acknowledge assignment | Open assignments and acknowledge. | Request becomes ready for scheduling. |  | Pending |  |  |
| AUT-06 | Provider | Assignment privacy | Open the assigned case. | Only the service-relevant condition (during execution), the minimal contact, no national id, no finance, no internal notes, no diagnosis/hospital/doctor. |  | Pending |  |  |
| AUT-07 | Provider | Documents sharing | Open the case documents. | Only documents explicitly shared with providers. |  | Pending |  |  |
| AUT-08 | Provider | Document uploader privacy | Open the shared documents list of an assigned case. | File name, type, date and download work; the uploader shows 'جمعية راحة' (staff) or 'المستفيد', never an employee or account name. |  | Pending |  |  |
| AUT-09 | Care manager | Document uploader (staff view) | Open the documents tab of the same case as staff. | Staff still see the real uploader names. |  | Pending |  |  |

## 9. Visits

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| VIS-01 | Provider | Schedule a visit | Schedule visits for an assigned multi-visit request. | Sequence numbers unique; cannot exceed the approved count. |  | Pending |  |  |
| VIS-02 | Provider | Complete / miss / cancel a visit | Complete one visit, mark another missed (with a reason), cancel one. | Status and history recorded; progress percentage correct. |  | Pending |  |  |
| VIS-03 | Care manager | Correct a visit | Correct a completed visit to missed with a reason. | Previous values kept in the history; a reason is mandatory. |  | Pending |  |  |
| VIS-04 | Provider | Correction visibility | Open the corrected visit as the provider. | The correction is visible, but it names only 'جمعية راحة' (no staff name) and shows no internal reason. |  | Pending |  |  |
| VIS-05 | Care manager | Adjust approved count | Reduce/increase the approved visit count. | Never deletes history; excess scheduled visits need an explicit choice. |  | Pending |  |  |
| VIS-06 | Care manager | Visit monitoring | Open visit monitoring; filter by provider/status. | Counts and overdue visits correct. |  | Pending |  |  |
| VIS-07 | Provider | Start / finish service | Start the service, then finish it after the last visit. | Cannot finish before the required visits are completed. |  | Pending |  |  |

## 10. Closure and archive

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| CLS-01 | Provider | Upload invoice | Upload the invoice amount once. | Second upload refused. |  | Pending |  |  |
| CLS-02 | Patient relations | Relations review | Confirm the beneficiary's confirmation and evaluation. | Request advances to finance review. |  | Pending |  |  |
| CLS-03 | Finance | Close and archive | Confirm payment and archive. | Status 'archived'; archived requests refuse further actions. |  | Pending |  |  |
| CLS-04 | Auditor | Archive immutability | Try any action on an archived request. | No actions offered; direct API call is refused. |  | Pending |  |  |

## 11. Reports and exports

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| REP-01 | Admin | Reports page | Open each of the report types, apply date filters. | Each loads without error; filters change the rows. |  | Pending |  |  |
| REP-02 | Admin | Excel export | Export every report type to Excel and open the files. | File opens; Arabic readable; row counts equal the screen; no formulas errors. |  | Pending |  |  |
| REP-03 | Admin | CSV export | Export CSV and open in a spreadsheet. | UTF-8 Arabic correct; columns equal the screen. |  | Pending |  |  |
| REP-04 | Auditor | Export permission | Open reports as a role without export rights. | Export buttons absent; direct download refused. |  | Pending |  |  |
| REP-05 | Admin | Table print/export toolbar | On a data table use date range, print and Excel. | Only the filtered rows; RTL print layout. |  | Pending |  |  |
| REP-06 | Executive | Analytics numbers | Compare dashboard/analytics totals with the case list. | Totals match. |  | Pending |  |  |

## 12. Service and provider catalogs

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| CAT-01 | Admin | Create service | Create a service with required documents and visit plan; reload. | Persisted; appears in the public/portal lists. |  | Pending |  |  |
| CAT-02 | Admin | Edit service | Change the description/documents; save. | Change visible; new requests use it, old requests keep their snapshot. |  | Pending |  |  |
| CAT-03 | Admin | Create provider | Create a provider with coverage cities and a logo (PNG/JPEG). | Persisted; a non-image file is refused. |  | Pending |  |  |
| CAT-04 | Admin | Provider detail | Open the provider detail and its eligibility for services. | Eligibility matches coverage. |  | Pending |  |  |
| CAT-05 | Auditor | Catalog permissions | Open the catalog without edit rights. | No create/edit buttons. |  | Pending |  |  |

## 13. Users and roles

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| USR-01 | Admin | Create user | Create a staff user with a role. | One-time temporary password shown; user must change it at first sign-in. |  | Pending |  |  |
| USR-02 | New user | Forced password change | Sign in with the temporary password; try a weak password, then a strong one. | Weak refused; strong accepted; access granted. |  | Pending |  |  |
| USR-03 | Admin | Suspend / activate | Suspend a user, then activate. | Suspended user cannot sign in and sessions end; activation restores access. |  | Pending |  |  |
| USR-04 | Admin | Lock / unlock | Lock a user, then unlock. | Locked user cannot sign in; unlock restores access. |  | Pending |  |  |
| USR-05 | Admin | End sessions / reset MFA | End all sessions of a user. | User is signed out on the next request. |  | Pending |  |  |
| USR-06 | Admin | Create custom role | Create a role with selected permissions and assign it. | User gets exactly those permissions; menus follow. |  | Pending |  |  |
| USR-07 | Admin | Permission scope | Open a role with 'own'/'all' scopes and test the effect. | Scope is enforced by the server. |  | Pending |  |  |
| USR-08 | Auditor | No access | Open /users-roles. | No data. |  | Pending |  |  |

## 14. Organization settings

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| ORG-01 | Admin | Organization identity | Edit the names and contact data; upload a logo; save; reload. | Saved; typed text is not lost when an image is uploaded. |  | Pending |  |  |
| ORG-02 | Admin | Signature and stamp | Upload a signature and a stamp (PNG/JPEG); try another file type. | Accepted/refused correctly; used on issued documents. |  | Pending |  |  |
| ORG-03 | Admin | Language default | Change the system default language. | New sessions follow it. |  | Pending |  |  |
| ORG-04 | Admin | Security policy | Change idle timeout/lock thresholds. | Policy applies to new sessions; audited. |  | Pending |  |  |
| ORG-05 | Non-admin | Settings access | Open the settings page without rights. | Read-only or denied as designed. |  | Pending |  |  |

## 15. Notifications

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| NOT-01 | Any | Bell and list | Trigger an action that notifies you; open the notifications page. | Notification appears with a working link; unread count correct. |  | Pending |  |  |
| NOT-02 | Any | Mark read | Mark one and all as read; reload. | State persists. |  | Pending |  |  |
| NOT-03 | Any | Preferences | Change notification preferences. | Saved per device. |  | Pending |  |  |
| NOT-04 | Provider/Beneficiary | Privacy | Read the notification texts. | No internal notes or other people's data. |  | Pending |  |  |

## 16. Audit

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| AUD-01 | Admin | Audit trail | Open the audit log; filter by user/action/date. | Actions from this UAT are listed with who/when; 25 rows per page. |  | Pending |  |  |
| AUD-02 | Admin | Record history | Open the user/case activity tab. | Matches the actions performed. |  | Pending |  |  |
| AUD-03 | Auditor | Audit permission | Open /audit. | Allowed only if the role holds the audit permission. |  | Pending |  |  |

## 17. Account security

| Test ID | Role/account | Module/page | Steps | Expected result | Actual result | Pass/Fail | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|
| SEC-01 | Any staff | Session idle timeout | Stay idle beyond the configured time, then click. | Signed out and returned to login. |  | Pending |  |  |
| SEC-02 | Any | Session ended elsewhere | Sign in twice; end the first session from the second. | First browser is signed out on its next request. |  | Pending |  |  |
| SEC-03 | Any | Own security panel | Open the account security panel; change password. | Policy enforced; old password stops working. |  | Pending |  |  |
| SEC-04 | Any | Direct URL access | Open a screen outside your role by typing the URL. | No data; access-denied page. |  | Pending |  |  |
| SEC-05 | Provider | Portal confinement | Open /dashboard, /finance, /users-roles. | Redirect/denied. |  | Pending |  |  |
| SEC-06 | Any | Server down | Stop the API and use the app. | Clear Arabic/English message; no raw server text; recovers when the API returns. |  | Pending |  |  |
| SEC-07 | Any | Slow network | Throttle the network and submit a form. | No double submission; clear progress/failure message. |  | Pending |  |  |
| SEC-08 | Any | Request timeout — read | Make the API stop answering (or throttle to offline-like stall) and open a list page; wait about 30 seconds. | A clear localized message appears instead of endless loading; the page recovers on retry. |  | Pending |  |  |
| SEC-09 | Finance | Request timeout — write | Stall the API while confirming an action (for example payment) and wait about 30 seconds. | Message says the action MAY have been completed and to check the status; a 'Check status' button re-reads data; the action is NOT re-sent automatically; verify the real state in the case page before repeating. |  | Pending |  |  |
| SEC-10 | Any | Cancelled navigation | Start a page load and navigate away immediately. | No error toast for the cancelled request. |  | Pending |  |  |
| SEC-11 | Any | Responsive layouts [M] | Open each portal at 390 px. | No horizontal scroll; controls at least 24 px. |  | Pending |  |  |
| SEC-12 | Any | Arabic RTL / English | Switch languages on three screens. | Layout mirrors; no untranslated text except the language switch. |  | Pending |  |  |

---
Total test cases: **145**. Sign-off: tester ______  date ______  environment ______  build ______
