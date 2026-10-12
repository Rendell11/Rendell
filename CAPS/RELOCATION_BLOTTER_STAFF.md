# Relocated residents: Blotter + Staff/BPSO

## Blotter
- Case details and the case report show both addresses when a resident party moved (Households → Remove → "Household relocated"):
  - **Address (when filed)**: the old address, kept as it was recorded on the case.
  - **New address**: the resident's current address.
- The party also gets a **Relocated** tag, or **Relocated (moved out)** when the new address is in another barangay.
- New letters and notices (Generate Notice, transfer letter) use the new address. Notices that were already generated keep their saved copy.
- Certificates already read the resident's current address.

## Staff / BPSO
- A personnel whose household relocated after they started gets a yellow note (date, old address → new address).
- **Block**: the record stays and nothing is deleted.
  - The work email cannot log in. A staff member who is already logged in is logged out on their next click.
  - Blocked staff are left out of the BPSO duty planner and staff attendance.
- **Unblock**: when they move back, the account, duty and attendance work again.
- **End Term**: still removes them the same way as before.
- Staff whose term was ended can no longer log in.
- New `staff` columns are added automatically: IsBlocked, BlockReason, BlockedBy, BlockedByID, BlockedAt, UnblockedBy, UnblockedAt.

## Files
- admin/blotter/backend/blotter_common.php, blotter_cases.php, case_report.php
- admin/blotter/frontend/case_details.php
- admin/staffbpso/backend/staffbpso_common.php, process_staff.php, tanod_action.php, staff_attendance_action.php
- admin/staffbpso/frontend/staff.php, tanod_management.php
- admin/auth_check.php, login/auth_login.php, login/login.php
