<p align="center">
  <img src="https://via.placeholder.com/400x100?text=EMC+Platform" width="400" alt="EMC Logo">
</p>

<h1 align="center">EMC Backend API</h1>

<p align="center">
Global educational platform backend built with Laravel.
</p>

---

## 🚀 About Project

EMC is a global educational platform that provides:

* Language courses (English, Dutch, Arabic, German)
* Educational consulting
* University admission guidance
* CV review & job readiness coaching
* Professional training (AI, Data Science, Marketing, Project Management)

This backend serves a REST API for the frontend.

---

## ⚙️ Setup

```bash
git clone <backend-repo-url>
cd emc-backend

composer install
cp .env.example .env
php artisan key:generate
```

---

## 🧱 Database

```bash
php artisan migrate
```

---

## ▶️ Run Server

```bash
php artisan serve
```

---

## 🌐 API Base URL

```
http://127.0.0.1:8000/api
```

---

## 🧠 Features

* Courses with:

  * Instructor
  * Learning features (outcomes)
* Registration system
* Payment integration:

  * Stripe
  * PayPal
* Admin dashboard API

---

## 🔐 Environment Example

```env
DB_DATABASE=emc_db
DB_USERNAME=root
DB_PASSWORD=

STRIPE_KEY=pk_test_xxx
STRIPE_SECRET=sk_test_xxx

PAYPAL_MODE=sandbox
PAYPAL_SANDBOX_CLIENT_ID=xxx
PAYPAL_SANDBOX_CLIENT_SECRET=xxx

FRONTEND_URL=http://localhost:5173
```

---

## 📌 Notes

* API only (no Blade views)
* Used by React frontend
* Supports online & in-person courses
