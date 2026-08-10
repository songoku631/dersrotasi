import {
  BarChart3,
  CalendarCheck,
  Clock3,
} from 'lucide-react'

export const tools = [
  {
    title: 'YKS Puan - Sıralama',
    description: 'ÖSYM puanını gir, geçmiş yılların resmî başarı sırası aralıklarını karşılaştır.',
    path: '/yks-siralama-tahmini',
    icon: BarChart3,
  },
  {
    title: 'Pomodoro',
    description: 'Odak ve mola sürelerini sade bir zamanlayıcıyla yönet.',
    path: '/pomodoro',
    icon: Clock3,
  },
  {
    title: 'Çalışma Planı',
    description: 'Haftalık hedeflerini derslere göre planlamak için başlangıç noktası.',
    path: '/calisma-plani',
    icon: CalendarCheck,
  },
]

export const toolMenuItems = tools.filter((tool) => tool.path !== '/calisma-plani')
