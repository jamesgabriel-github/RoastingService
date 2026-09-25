import { Route, Routes } from 'react-router-dom'
import { AdminLayout } from '@/components/layouts/AdminLayout'
import { CustomerLayout } from '@/components/layouts/CustomerLayout'
import { PublicLayout } from '@/components/layouts/PublicLayout'
import { AccountPage } from '@/features/auth/AccountPage'
import { AdminLoginPage } from '@/features/auth/AdminLoginPage'
import { LoginPage } from '@/features/auth/LoginPage'
import { ProfileSetupPage } from '@/features/auth/ProfileSetupPage'
import { AdminAccountsPage } from '@/features/admin-accounts/AdminAccountsPage'
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
        </Route>
      </Route>

      <Route path="/admin/login" element={<AdminLoginPage />} />

      <Route element={<RoleRoute allow={['admin', 'super_admin']} />}>
        <Route element={<AdminLayout />}>
          <Route path="/admin" element={<div>Admin</div>} />

          <Route element={<RoleRoute allow={['super_admin']} />}>
            <Route path="/admin/accounts" element={<AdminAccountsPage />} />
          </Route>
        </Route>
      </Route>
    </Routes>
  )
}

export default App
