# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary users are the students and parents of Greenfield Senior High School applying for and completing enrollment, including strand selection (ABM, STEM, HUMSS, ICT, GAS). Secondary users are school staff operating the internal portals: registrar, admissions coordinator, scheduler, treasury/cashier staff, teachers, and general staff — each processing their part of the admission-to-enrollment pipeline (document review, section/schedule assignment, payment recording, grading/announcements).

## Product Purpose

A full school enrollment and operations system for a Philippine Senior High School: it takes an applicant from admission/application through document submission, strand and section placement, scheduling, payment/treasury processing, and ongoing academic operations (teacher portal, learning portal, announcements), replacing manual/paper-based enrollment workflows.

## Positioning

A single system spanning both the public-facing admission/enrollment flow and the internal multi-role operations (registrar, scheduler, treasury, teachers) that process each enrollment — rather than a standalone application form or a separate SIS.

## Operating Context

Operates within Philippine DepEd (Department of Education) Senior High School rules and terminology: strand-based tracks (ABM, STEM, HUMSS, ICT, GAS), standard required admission documents (e.g. Form 137/138-type records), and DepEd-aligned enrollment periods. Distinct role-based portals exist for: admin, coordinator, registrar, scheduler, staff, student/studentportal, teacherportal, treasury, and learningportal.

## Capabilities and Constraints

- Stack: PHP (no framework scaffold detected) with Composer-managed dependencies (PHPMailer for email/notifications).
- Public marketing/admission entry point lives at `student/` (root `index.php` redirects there); authenticated portals are separated by role directory.
- Must remain compliant with DepEd Philippine SHS admission requirements and terminology — do not invent or alter official document/requirement names.
- Existing strand images (`images/admission_strand/ABM.jpeg`, `GAS.png`, `HUMSS.jpg`, `ICT.jpg`, `STEM.png`) are real content, not placeholders — preserve or intentionally replace with equivalent real assets, not fabricated stand-ins.

## Brand Commitments

- School identity: **Greenfield Senior High School** — confirmed as the real, binding name and branding.
- Existing visual identity (already implemented in `student/index.php` and shared with the student portal) is binding: green/cream palette (`--color-primary:#386641`, `--color-dark:#1C2628`, `--color-accent:#FEFAE0`, etc.), `Inter` (UI text) and `Fraunces` (headings) typefaces, pill-shaped buttons/nav, soft rounded cards. Preserve this world for future work unless the user explicitly requests a redesign.

## Evidence on Hand

- Real strand imagery under `images/admission_strand/`.
- Background/UI imagery under `images/background/` and `images/logo*.png`.
- No confirmed testimonials, case studies, or press — do not fabricate these.

## Product Principles

1. Respect DepEd/Philippine SHS compliance and terminology in all admission and records flows — never invent official-sounding requirements or documents.
2. One coherent visual identity (Greenfield SHS green/cream, Inter + Fraunces) spans the public site and every portal; role-specific portals should feel like the same product, not separate apps.
3. The system serves two very different audiences in one product: public applicants/parents (persuade/onboard) and internal staff across five+ roles (operate) — design decisions should match the mode of the surface being touched, not blend the two.
4. Prefer real, verifiable content and assets over placeholder or fabricated content, especially for anything DepEd-facing or officially documented.

## Accessibility & Inclusion

No project-specific accessibility requirement has been established beyond general good practice.
