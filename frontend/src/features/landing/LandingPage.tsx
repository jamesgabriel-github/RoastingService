import { AboutSection } from './AboutSection'
import { ContactSection } from './ContactSection'
import { HeroSection } from './HeroSection'
import { HowItWorksSection } from './HowItWorksSection'
import { ServicesSection } from './ServicesSection'

export function LandingPage() {
  return (
    <div className="flex flex-col divide-y divide-amber-100">
      <HeroSection />
      <AboutSection />
      <ServicesSection />
      <HowItWorksSection />
      <ContactSection />
    </div>
  )
}
