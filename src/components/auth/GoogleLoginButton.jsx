import { LogIn } from 'lucide-react'
import Button from '../Button'

function GoogleLoginButton({ isLoading = false, onClick }) {
  return (
    <Button
      aria-label="Google hesabı ile giriş yap"
      disabled={isLoading}
      icon={LogIn}
      type="button"
      onClick={onClick}
    >
      {isLoading ? 'Giriş yapılıyor...' : 'Google ile Giriş Yap'}
    </Button>
  )
}

export default GoogleLoginButton
