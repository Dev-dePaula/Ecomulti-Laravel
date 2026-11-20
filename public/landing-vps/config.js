/* Configuração da landing page de VPS — altere só aqui */
window.VPS = {
  whatsapp: "244923340895",
  email: "suporte@ecomulti.ao",
  /* Quando tiver o gateway Multicaixa Express (EMIS / agregador), coloque aqui o endereço
     da sua API que cria o pagamento. Recebe POST JSON {pedido, plano, ciclo, total, cliente, telefoneMcx}
     e deve responder {ok:true}. Vazio = pedido é confirmado pelo WhatsApp. */
  apiPagamento: "",
  iva: 0, // ex.: 0.14 para somar 14% de IVA
  ciclos: {
    mensal: { meses: 1, desc: 0, nome: "Mensal" },
    trimestral: { meses: 3, desc: 0.05, nome: "Trimestral (-5%)" },
    anual: { meses: 12, desc: 0.15, nome: "Anual (-15%)" }
  },
  planos: {
    start: { nome: "VPS Start", preco: 35000, cpu: "2 vCPU", ram: "4 GB RAM", disco: "60 GB NVMe", banda: "2 TB", itens: ["Linux à escolha (Ubuntu, Debian, AlmaLinux)", "IP dedicado IPv4", "Acesso root total", "Backup semanal", "Suporte por WhatsApp"] },
    pro: { nome: "VPS Pro", preco: 65000, cpu: "4 vCPU", ram: "8 GB RAM", disco: "120 GB NVMe", banda: "4 TB", itens: ["Linux ou Windows Server", "IP dedicado IPv4", "Acesso root / administrador", "Backup diário automático", "Firewall e protecção DDoS", "Suporte prioritário 24/7"] },
    elite: { nome: "VPS Elite", preco: 120000, cpu: "8 vCPU", ram: "16 GB RAM", disco: "240 GB NVMe", banda: "8 TB", itens: ["Linux ou Windows Server", "2 IPs dedicados", "Acesso root / administrador", "Backup diário + snapshots", "Firewall, DDoS e monitorização", "Migração gratuita assistida", "Gestor de conta dedicado"] }
  }
};
