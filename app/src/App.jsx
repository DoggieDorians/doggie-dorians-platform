import { useEffect, useMemo, useState } from "react";
import "./App.css";

const API_BASE = "https://dorianspetcare.com";

const ENDPOINTS = {
  session: `${API_BASE}/api/app/session.php`,
  login: `${API_BASE}/api/app/login.php`,
  bookings: `${API_BASE}/api/app/bookings.php`,
  createBooking: `${API_BASE}/api/app/create-booking.php`,
  logout: `${API_BASE}/api/app/logout.php`,
};

const SERVICE_OPTIONS = [
  "Dog Walk",
  "Daycare",
  "Boarding",
  "Pet Sitting",
  "Drop-In Visit",
];

function App() {
  const [bootLoading, setBootLoading] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [member, setMember] = useState(null);

  const [activeTab, setActiveTab] = useState("home");

  const [loginIdentifier, setLoginIdentifier] = useState("");
  const [loginPassword, setLoginPassword] = useState("");
  const [loginLoading, setLoginLoading] = useState(false);
  const [loginError, setLoginError] = useState("");

  const [bookings, setBookings] = useState([]);
  const [bookingsLoading, setBookingsLoading] = useState(false);
  const [bookingsError, setBookingsError] = useState("");

  const [createServiceType, setCreateServiceType] = useState("Dog Walk");
  const [createPetName, setCreatePetName] = useState("");
  const [createBookingDate, setCreateBookingDate] = useState("");
  const [createBookingTime, setCreateBookingTime] = useState("");
  const [createNotes, setCreateNotes] = useState("");
  const [createLoading, setCreateLoading] = useState(false);
  const [createError, setCreateError] = useState("");
  const [createSuccess, setCreateSuccess] = useState("");

  useEffect(() => {
    bootstrapSession();
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadBookings();
    }
  }, [isAuthenticated]);

  async function bootstrapSession() {
    try {
      setBootLoading(true);

      const response = await fetch(ENDPOINTS.session, {
        method: "GET",
        credentials: "include",
      });

      const data = await response.json();

      if (data?.success && data?.logged_in) {
        setIsAuthenticated(true);
        setMember(data.user || data.member || null);
      } else {
        setIsAuthenticated(false);
        setMember(null);
      }
    } catch (error) {
      console.error("Session bootstrap failed:", error);
      setIsAuthenticated(false);
      setMember(null);
    } finally {
      setBootLoading(false);
    }
  }

  async function handleLogin(e) {
    e.preventDefault();

    setLoginLoading(true);
    setLoginError("");

    try {
      const response = await fetch(ENDPOINTS.login, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          identifier: loginIdentifier,
          password: loginPassword,
        }),
      });

      const data = await response.json();

      if (!data?.success) {
        setLoginError(data?.message || "Login failed.");
        return;
      }

      setIsAuthenticated(true);
      setMember(data.user || data.member || null);
      setLoginIdentifier("");
      setLoginPassword("");
      setActiveTab("home");
      await loadBookings();
    } catch (error) {
      console.error("Login error:", error);
      setLoginError("Unable to log in right now.");
    } finally {
      setLoginLoading(false);
    }
  }

  async function loadBookings() {
    try {
      setBookingsLoading(true);
      setBookingsError("");

      const response = await fetch(ENDPOINTS.bookings, {
        method: "GET",
        credentials: "include",
      });

      const data = await response.json();

      if (!data?.success) {
        setBookings([]);
        setBookingsError(data?.message || "Could not load bookings.");
        return;
      }

      setBookings(Array.isArray(data.bookings) ? data.bookings : []);
    } catch (error) {
      console.error("Bookings load error:", error);
      setBookings([]);
      setBookingsError("Unable to load bookings.");
    } finally {
      setBookingsLoading(false);
    }
  }

  async function handleCreateBooking(e) {
    e.preventDefault();

    setCreateError("");
    setCreateSuccess("");

    if (!createServiceType || !createPetName || !createBookingDate || !createBookingTime) {
      setCreateError("Please complete service, pet name, date, and time.");
      return;
    }

    try {
      setCreateLoading(true);

      const response = await fetch(ENDPOINTS.createBooking, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          service_type: createServiceType,
          pet_name: createPetName,
          booking_date: createBookingDate,
          booking_time: createBookingTime,
          notes: createNotes,
        }),
      });

      const data = await response.json();

      if (!data?.success) {
        setCreateError(data?.message || "Could not create booking.");
        return;
      }

      setCreateSuccess("Booking created successfully.");
      setCreateServiceType("Dog Walk");
      setCreatePetName("");
      setCreateBookingDate("");
      setCreateBookingTime("");
      setCreateNotes("");

      await loadBookings();
      setActiveTab("bookings");
    } catch (error) {
      console.error("Create booking error:", error);
      setCreateError("Unable to create booking right now.");
    } finally {
      setCreateLoading(false);
    }
  }

  async function handleLogout() {
    try {
      await fetch(ENDPOINTS.logout, {
        method: "POST",
        credentials: "include",
      });
    } catch (error) {
      console.error("Logout request failed:", error);
    } finally {
      setIsAuthenticated(false);
      setMember(null);
      setBookings([]);
      setActiveTab("home");
      setCreateError("");
      setCreateSuccess("");
    }
  }

  const upcomingBookings = useMemo(() => {
    return bookings.filter((booking) => {
      const status = String(booking.status || "").toLowerCase();
      return status !== "completed" && status !== "cancelled";
    });
  }, [bookings]);

  const firstName =
    member?.first_name ||
    member?.name?.split(" ")?.[0] ||
    member?.username ||
    "Member";

  if (bootLoading) {
    return (
      <div className="app-shell">
        <div className="center-screen">
          <div className="loader-circle" />
          <h2>Loading Doggie Dorian’s</h2>
          <p>Checking your session…</p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return (
      <div className="app-shell auth-shell">
        <div className="auth-card">
          <div className="brand-block">
            <p className="eyebrow">DOGGIE DORIAN’S</p>
            <h1>Member App</h1>
            <p className="muted">
              Log in to manage bookings, view updates, and access your account.
            </p>
          </div>

          <form onSubmit={handleLogin} className="auth-form">
            <label>
              Email or Username
              <input
                type="text"
                value={loginIdentifier}
                onChange={(e) => setLoginIdentifier(e.target.value)}
                placeholder="Enter your email or username"
                autoComplete="username"
              />
            </label>

            <label>
              Password
              <input
                type="password"
                value={loginPassword}
                onChange={(e) => setLoginPassword(e.target.value)}
                placeholder="Enter your password"
                autoComplete="current-password"
              />
            </label>

            {loginError ? <div className="error-box">{loginError}</div> : null}

            <button type="submit" className="primary-btn" disabled={loginLoading}>
              {loginLoading ? "Logging in..." : "Log In"}
            </button>
          </form>
        </div>
      </div>
    );
  }

  return (
    <div className="app-shell">
      <header className="top-header">
        <div>
          <p className="eyebrow">DOGGIE DORIAN’S</p>
          <h2>Welcome back, {firstName}</h2>
        </div>

        <button className="ghost-btn" onClick={handleLogout}>
          Log Out
        </button>
      </header>

      <main className="main-content">
        {activeTab === "home" && (
          <HomeTab
            member={member}
            upcomingBookings={upcomingBookings}
            onRefresh={loadBookings}
            bookingsLoading={bookingsLoading}
            bookingsError={bookingsError}
            setActiveTab={setActiveTab}
          />
        )}

        {activeTab === "bookings" && (
          <BookingsTab
            bookings={bookings}
            bookingsLoading={bookingsLoading}
            bookingsError={bookingsError}
            onRefresh={loadBookings}
          />
        )}

        {activeTab === "create" && (
          <CreateBookingTab
            createServiceType={createServiceType}
            setCreateServiceType={setCreateServiceType}
            createPetName={createPetName}
            setCreatePetName={setCreatePetName}
            createBookingDate={createBookingDate}
            setCreateBookingDate={setCreateBookingDate}
            createBookingTime={createBookingTime}
            setCreateBookingTime={setCreateBookingTime}
            createNotes={createNotes}
            setCreateNotes={setCreateNotes}
            createLoading={createLoading}
            createError={createError}
            createSuccess={createSuccess}
            handleCreateBooking={handleCreateBooking}
          />
        )}

        {activeTab === "account" && <AccountTab member={member} />}
      </main>

      <nav className="bottom-nav bottom-nav-four">
        <button
          className={activeTab === "home" ? "nav-btn active" : "nav-btn"}
          onClick={() => setActiveTab("home")}
        >
          Home
        </button>

        <button
          className={activeTab === "bookings" ? "nav-btn active" : "nav-btn"}
          onClick={() => setActiveTab("bookings")}
        >
          Bookings
        </button>

        <button
          className={activeTab === "create" ? "nav-btn active" : "nav-btn"}
          onClick={() => setActiveTab("create")}
        >
          Book
        </button>

        <button
          className={activeTab === "account" ? "nav-btn active" : "nav-btn"}
          onClick={() => setActiveTab("account")}
        >
          Account
        </button>
      </nav>
    </div>
  );
}

function HomeTab({
  member,
  upcomingBookings,
  onRefresh,
  bookingsLoading,
  bookingsError,
  setActiveTab,
}) {
  return (
    <section className="tab-screen">
      <div className="hero-card">
        <p className="eyebrow">MEMBER DASHBOARD</p>
        <h1>Your dog care, now mobile.</h1>
        <p className="muted">
          Track your services, review upcoming bookings, and stay connected to
          Doggie Dorian’s from anywhere.
        </p>

        <div className="hero-actions">
          <button className="primary-btn" onClick={() => setActiveTab("create")}>
            Book a Service
          </button>
          <button className="secondary-btn" onClick={onRefresh}>
            Refresh
          </button>
        </div>
      </div>

      <div className="stats-grid">
        <div className="stat-card">
          <span className="stat-label">Upcoming</span>
          <strong>{upcomingBookings.length}</strong>
        </div>

        <div className="stat-card">
          <span className="stat-label">Member</span>
          <strong>{member?.membership_type || "Active"}</strong>
        </div>
      </div>

      <div className="panel-card">
        <div className="panel-header">
          <h3>Upcoming Services</h3>
          <button className="inline-btn" onClick={onRefresh}>
            Refresh
          </button>
        </div>

        {bookingsLoading ? (
          <p className="muted">Loading bookings…</p>
        ) : bookingsError ? (
          <div className="error-box">{bookingsError}</div>
        ) : upcomingBookings.length === 0 ? (
          <p className="muted">No upcoming bookings found.</p>
        ) : (
          <div className="booking-list">
            {upcomingBookings.slice(0, 3).map((booking) => (
              <BookingCard key={booking.id || booking.booking_id} booking={booking} />
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

function BookingsTab({ bookings, bookingsLoading, bookingsError, onRefresh }) {
  return (
    <section className="tab-screen">
      <div className="panel-card">
        <div className="panel-header">
          <h3>All Bookings</h3>
          <button className="inline-btn" onClick={onRefresh}>
            Refresh
          </button>
        </div>

        {bookingsLoading ? (
          <p className="muted">Loading bookings…</p>
        ) : bookingsError ? (
          <div className="error-box">{bookingsError}</div>
        ) : bookings.length === 0 ? (
          <p className="muted">No bookings found yet.</p>
        ) : (
          <div className="booking-list">
            {bookings.map((booking) => (
              <BookingCard key={booking.id || booking.booking_id} booking={booking} />
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

function CreateBookingTab({
  createServiceType,
  setCreateServiceType,
  createPetName,
  setCreatePetName,
  createBookingDate,
  setCreateBookingDate,
  createBookingTime,
  setCreateBookingTime,
  createNotes,
  setCreateNotes,
  createLoading,
  createError,
  createSuccess,
  handleCreateBooking,
}) {
  return (
    <section className="tab-screen">
      <div className="panel-card">
        <div className="panel-header">
          <h3>Book a Service</h3>
        </div>

        <form onSubmit={handleCreateBooking} className="auth-form">
          <label>
            Service
            <select
              value={createServiceType}
              onChange={(e) => setCreateServiceType(e.target.value)}
              className="app-select"
            >
              {SERVICE_OPTIONS.map((service) => (
                <option key={service} value={service}>
                  {service}
                </option>
              ))}
            </select>
          </label>

          <label>
            Pet Name
            <input
              type="text"
              value={createPetName}
              onChange={(e) => setCreatePetName(e.target.value)}
              placeholder="Enter your pet's name"
            />
          </label>

          <label>
            Date
            <input
              type="date"
              value={createBookingDate}
              onChange={(e) => setCreateBookingDate(e.target.value)}
            />
          </label>

          <label>
            Time
            <input
              type="time"
              value={createBookingTime}
              onChange={(e) => setCreateBookingTime(e.target.value)}
            />
          </label>

          <label>
            Notes
            <textarea
              value={createNotes}
              onChange={(e) => setCreateNotes(e.target.value)}
              placeholder="Anything we should know?"
              className="app-textarea"
              rows={4}
            />
          </label>

          {createError ? <div className="error-box">{createError}</div> : null}
          {createSuccess ? <div className="success-box">{createSuccess}</div> : null}

          <button type="submit" className="primary-btn" disabled={createLoading}>
            {createLoading ? "Creating Booking..." : "Create Booking"}
          </button>
        </form>
      </div>
    </section>
  );
}

function AccountTab({ member }) {
  return (
    <section className="tab-screen">
      <div className="panel-card">
        <h3>Account</h3>

        <div className="account-grid">
          <div className="account-item">
            <span>Name</span>
            <strong>{member?.name || "—"}</strong>
          </div>

          <div className="account-item">
            <span>Email</span>
            <strong>{member?.email || "—"}</strong>
          </div>

          <div className="account-item">
            <span>Phone</span>
            <strong>{member?.phone || "—"}</strong>
          </div>

          <div className="account-item">
            <span>Membership</span>
            <strong>{member?.membership_type || "Active"}</strong>
          </div>
        </div>
      </div>
    </section>
  );
}

function BookingCard({ booking }) {
  const service =
    booking.service_name ||
    booking.service_type ||
    booking.booking_type ||
    "Service";

  const petName = booking.pet_name || booking.dog_name || "Pet";
  const status = booking.status || "Pending";
  const dateText =
    booking.booking_date ||
    booking.service_date ||
    booking.start_date ||
    "Date unavailable";

  const timeText =
    booking.booking_time ||
    booking.start_time ||
    booking.time_slot ||
    "";

  return (
    <div className="booking-card">
      <div className="booking-card-top">
        <div>
          <h4>{service}</h4>
          <p className="muted">{petName}</p>
        </div>
        <span className={`status-pill status-${String(status).toLowerCase()}`}>
          {status}
        </span>
      </div>

      <div className="booking-meta">
        <div>
          <span>Date</span>
          <strong>{dateText}</strong>
        </div>

        <div>
          <span>Time</span>
          <strong>{timeText || "—"}</strong>
        </div>
      </div>
    </div>
  );
}

export default App;