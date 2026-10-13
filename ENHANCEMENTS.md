# SmartAttend Enhancements

## Recent Additions

### 1. User Management Enhancements (`admin/users.php`)
- **Profile Picture Upload** — Add/edit user photos with live preview
- **CSV Import Preview** — Validate CSV before importing with color-coded status (valid/duplicate/invalid)
- **Export to CSV/Excel** — Export all users or filter by role
- **Bulk Email Notifications** — Select multiple users and send custom emails
- **Password Reset** — Admin can reset any user's password via modal

### 2. Teacher Schedule Notifications (`teacher/announcements.php`)
- **Class Reminders** — Send students notifications for today/tomorrow classes
- Includes: class name, day, time, room, teacher name
- Sent via in-app notification + email (if configured)
- Only shows classes with schedules on selected day

### 3. Academic Management System

#### Rooms Management (`admin/rooms.php`)
- Add/edit classrooms, labs, lecture halls, seminar rooms, online rooms
- Track: name, building, capacity, type, facilities
- Activate/deactivate rooms
- Search and filter

#### Semester/Term Management (`admin/semesters.php`)
- Create academic terms (semester, trimester, quarter, year)
- Set start/end dates and academic year
- Mark current active term
- Auto-calculate status (upcoming/active/completed)
- Progress bar showing term completion

#### Academic Calendar (`admin/calendar.php`)
- Add events: holidays, exams, registration, orientation, breaks, deadlines
- Link events to specific terms
- Mark events that affect attendance (e.g., holidays)
- Filter by term and event type
- Visual timeline with color-coded event types

#### Enhanced Course Management (`admin/classes.php`)
- **Teacher Assignment** — Assign teachers with department info display
- **Room Assignment** — Link courses to specific rooms with capacity info
- **Term Assignment** — Associate courses with academic terms
- **Department Tracking** — Track course departments
- **Max Students** — Set enrollment limits
- **Activate/Deactivate** — Control course visibility
- **Teacher Workload View** — See courses and student count per teacher
- **Advanced Filters** — Filter by term, search across all fields

## Database Migration

Run `db/migration_academic.sql` to add new tables:
- `rooms` — Classroom/room management
- `academic_terms` — Semester/term tracking
- `academic_calendar` — Academic events
- Enhanced `classes` table with `term_id`, `room_id`, `department`, `max_students`, `is_active`
- Enhanced `schedules` table with `room_id`, `term_id`

## Navigation Updates

New admin menu items:
- Rooms
- Terms (Semesters)
- Calendar

## Usage

1. **Setup Academic Structure:**
   - Create departments (`admin/departments.php`)
   - Add rooms (`admin/rooms.php`)
   - Create academic terms (`admin/semesters.php`)
   - Set up calendar events (`admin/calendar.php`)

2. **Create Courses:**
   - Go to `admin/classes.php`
   - Fill in course details
   - Assign teacher, room, and term
   - Set max students and department

3. **Manage Users:**
   - Import via CSV with preview validation
   - Upload profile pictures
   - Send bulk emails to selected users
   - Export user data for reporting

4. **Teacher Notifications:**
   - Teachers can send class reminders from `teacher/announcements.php`
   - Select today/tomorrow and pick a class
   - System pulls schedule details automatically

## Features Summary

- ✅ Profile picture upload for all users
- ✅ CSV import with validation preview
- ✅ Export users to CSV/Excel
- ✅ Bulk email notifications
- ✅ Classroom/room management
- ✅ Academic term/semester tracking
- ✅ Academic calendar with event types
- ✅ Teacher assignment to courses
- ✅ Room assignment to courses
- ✅ Term assignment to courses
- ✅ Teacher workload overview
- ✅ Class schedule reminders for students
- ✅ Course activation/deactivation
- ✅ Advanced filtering and search
