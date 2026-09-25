import { Route, Routes } from 'react-router-dom'
import { AdminLayout } from '@/components/layouts/AdminLayout'
import { CustomerLayout } from '@/components/layouts/CustomerLayout'
import { PublicLayout } from '@/components/layouts/PublicLayout'
import { AccountPage } from '@/features/auth/AccountPage'
import { AdminLoginPage } from '@/features/auth/AdminLoginPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { ProfileSetupPage } from '@/features/auth/ProfileSetupPage'
import { AdminAccountsPage } from '@/features/admin-accounts/AdminAccountsPage'
import { AdminBookingDetailPage } from '@/features/admin-bookings/AdminBookingDetailPage'
import { AdminBookingsPage } from '@/features/admin-bookings/AdminBookingsPage'
import { WalkInBookingPage } from '@/features/admin-bookings/WalkInBookingPage'
import { PaymentsListPage } from '@/features/admin-payments/PaymentsListPage'
import { BookingDetailPage } from '@/features/bookings/BookingDetailPage'
import { BookingTypePage } from '@/features/bookings/BookingTypePage'
import { MyBookingsPage } from '@/features/bookings/MyBookingsPage'
import { NewBookingPage } from '@/features/bookings/NewBookingPage'
import { InventoryPage } from '@/features/inventory/InventoryPage'
import { NewShopOrderPage } from '@/features/orders/NewShopOrderPage'
import { ServicesPage } from '@/features/services/ServicesPage'
import { ProfileSetupRoute } from '@/routes/ProfileSetupRoute'
import { ProtectedRoute } from '@/routes/ProtectedRoute'
import { RoleRoute } from '@/routes/RoleRoute'

function App() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<div>Roasting Service</div>} />
        <Route path="/login" element={<LoginPage />} />
      </Route>

      <Route element={<ProfileSetupRoute />}>
        <Route element={<CustomerLayout />}>
          <Route path="/profile-setup" element={<ProfileSetupPage />} />
        </Route>
      </Route>

      <Route element={<ProtectedRoute />}>
        <Route element={<CustomerLayout />}>
          <Route path="/account" element={<AccountPage />} />
          <Route path="/book" element={<BookingTypePage />} />
          <Route path="/book/roasting" element={<NewBookingPage />} />
          <Route path="/book/shop" element={<NewShopOrderPage />} />
          <Route path="/bookings" element={<MyBookingsPage />} />
          <Route path="/bookings/:id" element={<BookingDetailPage />} />
        </Route>
      </Route>

      <Route path="/admin/login" element={<AdminLoginPage />} />

      <Route element={<RoleRoute allow={['admin', 'super_admin']} />}>
        <Route element={<AdminLayout />}>
          <Route path="/admin" element={<div>Admin</div>} />

          <Route element={<RoleRoute allow={['super_admin']} />}>
            <Route path="/admin/accounts" element={<AdminAccountsPage />} />
          </Route>

          <Route element={<RoleRoute allow={['admin', 'super_admin']} requireModule="services" />}>
            <Route path="/admin/services" element={<ServicesPage />} />
          </Route>

          <Route element={<RoleRoute allow={['admin', 'super_admin']} requireModule="inventory" />}>
            <Route path="/admin/inventory" element={<InventoryPage />} />
          </Route>

          <Route element={<RoleRoute allow={['admin', 'super_admin']} requireModule="bookings" />}>
            <Route path="/admin/bookings" element={<AdminBookingsPage />} />
            <Route path="/admin/bookings/walk-in" element={<WalkInBookingPage />} />
            <Route path="/admin/bookings/:id" element={<AdminBookingDetailPage />} />
          </Route>

          <Route element={<RoleRoute allow={['admin', 'super_admin']} requireModule="payments" />}>
            <Route path="/admin/payments" element={<PaymentsListPage />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}

export default App
